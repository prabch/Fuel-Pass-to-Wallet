/**
 * @fileoverview Main application logic for Fuel Pass to Apple Wallet.
 * Handles QR scanning, OCR extraction, form submission, and dynamic backend config.
 */

// ==========================================================================
// Globals & DOM Elements
// ==========================================================================
const video = document.getElementById('video-element');
const canvas = document.getElementById('canvas-element');
const ctx = canvas.getContext('2d');

let stream = null;
let scanning = false;
let appConfig = null;

// ==========================================================================
// UI & Interaction Helpers
// ==========================================================================

/**
 * Updates the status message banner.
 * @param {string} message - The message to display.
 * @param {'info'|'error'|'success'} [type='info'] - The type of status.
 */
function setStatus(message, type = 'info') {
  const statusEl = document.getElementById('status-message');
  statusEl.textContent = message;
  statusEl.className = `status ${type}`;
  statusEl.style.display = 'block';
}

/**
 * Clears and hides the status message banner.
 */
function clearStatus() {
  const statusEl = document.getElementById('status-message');
  statusEl.style.display = 'none';
  statusEl.className = 'status';
}

/**
 * Switches between the main navigation tabs.
 * @param {'upload'|'scan'|'manual'} tabId - The ID of the tab to switch to.
 */
function switchTab(tabId) {
  document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  
  document.getElementById(`tab-${tabId}`).classList.add('active');
  
  const btn = document.querySelector(`button[onclick="switchTab('${tabId}')"]`);
  if (btn) btn.classList.add('active');
  
  if (tabId !== 'scan') {
    stopScanner();
    const statusEl = document.getElementById('status-message');
    if (statusEl && statusEl.textContent === 'Camera access denied or unavailable.') {
      clearStatus();
    }
  }
}

// ==========================================================================
// File Upload & OCR Extraction
// ==========================================================================

document.getElementById('file-input').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = async (event) => {
    const imageUrl = event.target.result;
    document.getElementById('image-preview').src = imageUrl;
    document.getElementById('upload-preview-container').style.display = 'flex';
    
    setStatus('Analyzing image... Please wait.', 'info');
    
    try {
      await processImage(imageUrl);
    } catch (err) {
      console.error(err);
      setStatus('Failed to analyze image. Please enter manually.', 'error');
    }
  };
  reader.readAsDataURL(file);
});

/**
 * Processes an uploaded image to extract QR and text data.
 * @param {string} imageUrl - Base64 encoded image URL.
 */
async function processImage(imageUrl) {
  const img = new Image();
  
  img.onload = async () => {
    // 1. Draw image to canvas to extract pixel data for QR scanning
    canvas.width = img.width;
    canvas.height = img.height;
    ctx.drawImage(img, 0, 0, img.width, img.height);
    
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    
    // 2. Decode QR Code
    const code = jsQR(imageData.data, imageData.width, imageData.height, {
      inversionAttempts: "dontInvert",
    });

    if (code) {
      const isValid = parseQRData(code.data);
      if (!isValid) return;
    }

    // 3. Perform OCR for vehicle details not in the QR code
    setStatus('Extracting data...', 'info');
    await performOCR(imageUrl);

    setStatus('Extraction complete. Review details before generating the pass.', 'success');
    setTimeout(() => switchTab('manual'), 1500);
  };
  
  img.src = imageUrl;
}

/**
 * Parses raw QR data and updates form inputs.
 * @param {string} qrData - The raw string from the QR code.
 */
function parseQRData(qrData) {
  const parts = qrData.split('|').map(s => s.trim());
  const code = (parts.length >= 2 ? parts[1] : qrData).trim();

  if (code.length !== 12) {
    setStatus('Invalid QR: Code must be 12 characters long.', 'error');
    return false;
  }

  if (parts.length >= 2) {
    document.getElementById('vehicleNumber').value = parts[0];
    document.getElementById('permitCode').value = parts[1];
  } else {
    document.getElementById('permitCode').value = qrData;
  }
  document.getElementById('qrValue').value = qrData;
  return true;
}

/**
 * Performs OCR on an image and attempts to parse vehicle info.
 * @param {string} imageUrl - Base64 encoded image URL.
 */
async function performOCR(imageUrl) {
  const worker = await Tesseract.createWorker('eng');
  const ret = await worker.recognize(imageUrl);
  const text = ret.data.text;
  await worker.terminate();

  // Try parsing vehicle number if empty
  if (!document.getElementById('vehicleNumber').value) {
    const vNumMatch = text.match(/([A-Z0-9]{2,3}\s*-\s*\d{4})/i);
    if (vNumMatch) {
      document.getElementById('vehicleNumber').value = vNumMatch[1].replace(/\s+/g, '');
    }
  }

  // Try parsing vehicle type
  const vTypeMatch = text.match(/(Motor\s*Car|Motor\s*Cycle|Three\s*Wheel|Dual\s*Purpose|Lorry|Bus|Van)/i);
  if (vTypeMatch && appConfig) {
    const matched = vTypeMatch[1].toLowerCase().replace(/\s+/g, '');
    let selectValue = 'Other';
    
    Object.keys(appConfig.allowances).forEach(type => {
      const normalizedType = type.toLowerCase().replace(/\s+/g, '');
      if (matched.includes(normalizedType) || normalizedType.includes(matched)) {
        selectValue = type;
      }
    });
    
    document.getElementById('vehicleTypeLabel').value = selectValue;
    
    // Auto-assign quota
    if (appConfig.allowances[selectValue]) {
      document.getElementById('quotaLabel').value = appConfig.allowances[selectValue];
    }
  }

  // Try parsing quota
  const quotaMatch = text.match(/(\d+)\s*Liters?/i) || text.match(/(\d+L)/i);
  if (quotaMatch) {
    document.getElementById('quotaLabel').value = quotaMatch[1].includes('L') ? quotaMatch[1] : `${quotaMatch[1]}L`;
  }
}

// ==========================================================================
// Camera Scanner Logic
// ==========================================================================

/**
 * Requests camera permissions and starts the continuous QR scanner.
 */
async function startScanner() {
  document.getElementById('btn-start-scan').style.display = 'none';
  document.getElementById('btn-stop-scan').style.display = 'inline-flex';
  clearStatus();

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setStatus('Camera access denied or unavailable.', 'error');
    stopScanner();
    return;
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
    video.srcObject = stream;
    video.setAttribute("playsinline", true);
    await video.play();
    scanning = true;
    requestAnimationFrame(tick);
  } catch (err) {
    setStatus('Camera access denied or unavailable.', 'error');
    stopScanner();
  }
}

/**
 * Stops the camera stream and halts the scanner.
 */
function stopScanner() {
  if (stream) {
    stream.getTracks().forEach(track => track.stop());
  }
  scanning = false;
  document.getElementById('btn-start-scan').style.display = 'inline-flex';
  document.getElementById('btn-stop-scan').style.display = 'none';
}

/**
 * Animation frame loop that continuously checks the video feed for QR codes.
 */
function tick() {
  if (!scanning) return;
  
  if (video.readyState === video.HAVE_ENOUGH_DATA) {
    canvas.height = video.videoHeight;
    canvas.width = video.videoWidth;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code = jsQR(imageData.data, imageData.width, imageData.height, {
      inversionAttempts: "dontInvert",
    });

    if (code) {
      const isValid = parseQRData(code.data);
      if (!isValid) {
        requestAnimationFrame(tick);
        return;
      }
      
      stopScanner();
      setStatus('QR Code Scanned Successfully!', 'success');
      setTimeout(() => switchTab('manual'), 1000);
      return;
    }
  }
  
  requestAnimationFrame(tick);
}

// ==========================================================================
// Form Submission & Wallet Generation
// ==========================================================================

document.getElementById('pass-form').addEventListener('submit', (e) => {
  e.preventDefault();
  
  // Honeypot check to block bots
  const honeypot = document.getElementById('website_url').value;
  if (honeypot) {
    console.warn('Bot activity detected.');
    return;
  }
  
  // Rate limiting check: 3 passes per minute
  let submitTimes = [];
  try {
    const stored = localStorage.getItem('fp_last_submits');
    if (stored) submitTimes = JSON.parse(stored);
  } catch (e) {}
  
  const now = Date.now();
  // Filter out timestamps older than 60 seconds
  submitTimes = submitTimes.filter(time => now - time < 60000);
  
  if (submitTimes.length >= 3) {
    const oldestTime = submitTimes[0];
    const remaining = Math.ceil((60000 - (now - oldestTime)) / 1000);
    setStatus(`Please wait ${remaining} seconds before generating more passes.`, 'error');
    return;
  }

  const vehicleNumber = document.getElementById('vehicleNumber').value.trim();
  const permitCode = document.getElementById('permitCode').value.trim();

  // Basic JS validation
  if (!/^[a-zA-Z0-9]{2,3}\s*-\s*[0-9]{4}$/.test(vehicleNumber)) {
    setStatus('Invalid Vehicle Number format. Example: CAA-1234 or 252-1234.', 'error');
    return;
  }

  if (!/^[a-zA-Z0-9]{12}$/.test(permitCode)) {
    setStatus('Invalid Code. It must be exactly 12 alphanumeric characters.', 'error');
    return;
  }

  // Record the rate limit timestamp
  submitTimes.push(now);
  localStorage.setItem('fp_last_submits', JSON.stringify(submitTimes));
  const vehicleTypeLabel = document.getElementById('vehicleTypeLabel').value || 'Motor Car';
  const quotaLabel = document.getElementById('quotaLabel').value || '20L';
  const qrValue = document.getElementById('qrValue').value || `${vehicleNumber} | ${permitCode}`;

  const queryParams = new URLSearchParams({
    vehicleNumber,
    permitCode,
    vehicleTypeLabel,
    quotaLabel,
    qrValue,
    website_url: honeypot
  });

  const btn = document.getElementById('btn-generate');
  btn.disabled = true;
  btn.innerHTML = '<span class="loader"></span> Generating...';

  // Navigate to PHP backend to download pass
  window.location.href = `api.php?${queryParams.toString()}`;

  // Reset button state
  setTimeout(() => {
    btn.disabled = false;
    btn.textContent = 'Generate Apple Wallet Pass';
  }, 2000);
});

// ==========================================================================
// Backend Configuration Sync
// ==========================================================================

/**
 * Fetches dynamic vehicle types and rules from the PHP backend.
 * Unifies configuration to remain single-source-of-truth.
 */
async function loadConfig() {
  try {
    const res = await fetch('api.php?action=config');
    appConfig = await res.json();
    
    const select = document.getElementById('vehicleTypeLabel');
    select.innerHTML = '';
    
    // Populate dropdown options
    Object.keys(appConfig.allowances).forEach(type => {
      const opt = document.createElement('option');
      opt.value = type;
      opt.textContent = type;
      select.appendChild(opt);
    });
    
    // Auto-update allowance when vehicle type select changes
    select.addEventListener('change', () => {
      const selectedType = select.value;
      if (appConfig.allowances[selectedType]) {
        document.getElementById('quotaLabel').value = appConfig.allowances[selectedType];
      }
    });
    
    // Initialize default quota value
    if (select.value && appConfig.allowances[select.value]) {
      document.getElementById('quotaLabel').value = appConfig.allowances[select.value];
    }

    // Auto-detect type based on plate prefix (e.g. CAD -> C -> Motor Car)
    document.getElementById('vehicleNumber').addEventListener('input', (e) => {
      const val = e.target.value.trim();
      if (val && appConfig && appConfig.startingLetters) {
        // Strip optional province prefixes
        const cleanPlate = val.replace(/^(?:WP|CP|SP|EP|NW|NC|UP|VA|RU)\s+/i, '');
        const firstLetter = cleanPlate.charAt(0).toUpperCase();
        
        const detectedType = appConfig.startingLetters[firstLetter] || 'Motor Car';
        
        if (select.value !== detectedType) {
          select.value = detectedType;
          select.dispatchEvent(new Event('change'));
        }
      }
    });
  } catch (err) {
    console.error('Failed to load dynamic backend config:', err);
  }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', loadConfig);
