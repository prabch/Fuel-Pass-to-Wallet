<?php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net data: blob: https://fonts.googleapis.com https://fonts.gstatic.com;");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Convert your Sri Lanka Fuel QR into a digital Apple Wallet Pass">
  <meta name="keywords" content="Sri Lanka Fuel Pass, Apple Wallet, QR Code, Fuel Pass Sri Lanka, Digital Wallet Pass, pkpass">
  <meta name="robots" content="index, follow">
  <title>Fuel Pass to Wallet</title>

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="Fuel Pass to Wallet">
  <meta property="og:description" content="Convert your Sri Lanka Fuel QR into a digital Apple Wallet Pass">
  <meta property="og:image" content="/logo.png">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Fuel Pass to Wallet">
  <meta name="twitter:description" content="Convert your Sri Lanka Fuel QR into a digital Apple Wallet Pass">
  <meta name="twitter:image" content="/logo.png">

  <!-- Favicons and Web App Manifest -->
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="manifest" href="/site.webmanifest">
  <link rel="shortcut icon" href="/favicon.ico">
  <meta name="theme-color" content="#ffffff">
  
  <!-- Preconnect to Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  
  <!-- Stylesheets -->
  <link rel="stylesheet" href="style.css?v=<?= filemtime('style.css') ?>">
</head>
<body>
  <main class="container">
    <header class="header">
      <h1>Fuel Pass to Wallet</h1>
      <p class="subtitle">Convert your Sri Lanka Fuel QR into a digital Apple Wallet Pass</p>
    </header>

    <!-- Main Application Card -->
    <section class="glass-card" id="main-card">
      
      <!-- Navigation Tabs -->
      <nav class="tabs" aria-label="Input methods">
        <button class="tab-btn active" onclick="switchTab('upload')" aria-selected="true">Upload</button>
        <button class="tab-btn" onclick="switchTab('scan')" aria-selected="false">Scan</button>
        <button class="tab-btn" onclick="switchTab('manual')" aria-selected="false">Manual</button>
      </nav>

      <!-- Status Messaging Container -->
      <div id="status-message" class="status" role="alert"></div>

      <!-- Tab: Upload Image -->
      <div id="tab-upload" class="tab-content active" role="tabpanel">
        <div class="preview-container" id="upload-preview-container" style="display: none;">
          <img id="image-preview" src="" alt="QR code preview">
        </div>
        <div class="file-upload-wrapper">
          <label class="btn btn-secondary" for="file-input">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="17 8 12 3 7 8"></polyline>
              <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
            Select Screenshot
          </label>
          <input type="file" id="file-input" accept="image/*" aria-label="Upload screenshot">
        </div>
        <p style="text-align: center; margin-top: 15px; color: var(--text-secondary); font-size: 0.85rem;">
          Upload a screenshot of your Fuel Pass or just the QR code itself. Even a full webpage screenshot works. We'll automatically read the QR and extract your vehicle details for you.
        </p>
      </div>

      <!-- Tab: Camera Scanner -->
      <div id="tab-scan" class="tab-content" role="tabpanel">
        <div class="preview-container">
          <video id="video-element" playsinline aria-label="Camera viewfinder"></video>
          <div class="scan-overlay">
            <div class="scan-line"></div>
          </div>
          <canvas id="canvas-element" style="display:none;"></canvas>
        </div>
        <button class="btn btn-secondary" id="btn-start-scan" onclick="startScanner()">
          Start Camera
        </button>
        <button class="btn btn-secondary" id="btn-stop-scan" onclick="stopScanner()" style="display:none; margin-top:10px;">
          Stop Camera
        </button>
      </div>

      <!-- Tab: Manual Review & Generation -->
      <div id="tab-manual" class="tab-content" role="tabpanel">
        <form id="pass-form">
          <div class="form-group">
            <label for="vehicleNumber">Vehicle Number</label>
            <input type="text" id="vehicleNumber" class="form-control" placeholder="e.g. CAA-1234" pattern="^[a-zA-Z0-9]{2,3}\s*-\s*[0-9]{4}$" title="Vehicle number should be in format like CAA-1234 or 252-1234" required>
          </div>
          
          <div class="form-group">
            <label for="permitCode">Code (From QR)</label>
            <input type="text" id="permitCode" class="form-control" placeholder="e.g. FJ2HTUJUJA61" pattern="^[a-zA-Z0-9]{12}$" title="Permit code must be exactly 12 alphanumeric characters" minlength="12" maxlength="12" required>
          </div>

          <div class="form-group">
            <label for="vehicleTypeLabel">Vehicle Type</label>
            <select id="vehicleTypeLabel" class="form-control">
              <!-- Dynamically populated from backend config API -->
            </select>
          </div>

          <div class="form-group">
            <label for="quotaLabel">Weekly Quota</label>
            <input type="text" id="quotaLabel" class="form-control" placeholder="e.g. 25L">
          </div>

          <div style="position: absolute; left: -5000px;" aria-hidden="true">
            <label for="website_url">Website URL</label>
            <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
          </div>

          <!-- Hidden field to hold the raw QR string -->
          <input type="hidden" id="qrValue" value="">

          <button type="submit" class="btn" id="btn-generate" style="margin-top: 10px;">
            Generate Apple Wallet Pass
          </button>
        </form>
      </div>
    </section>

    <!-- Info Card -->
    <section class="info-card">
      <h2>What is an Apple Wallet Pass?</h2>
      <p>
        An Apple Wallet Pass is a digital card stored natively in the Wallet app. 
        It provides quick, offline access to your QR code on your iPhone or Apple Watch. 
        <a href="https://support.apple.com/en-lk/guide/iphone/iphe7aa3336/ios" target="_blank" rel="noopener noreferrer">Learn more</a>.
      </p>
    </section>

    <!-- Minimal Footer -->
    <footer class="footer">
      <p class="footer-privacy">Pass generation is processed dynamically on-the-fly. Your vehicle number, permit code, or QR details are never uploaded or stored on any server</p>
      <div class="footer-meta">
        <span>This project is open source; you can view and audit the code on <a href="https://github.com/prabch/Fuel-Pass-to-Wallet" target="_blank" rel="noopener noreferrer">GitHub</a>.</span>
        <span class="footer-separator">• • •</span>
        <span class="footer-disclaimer">This is an independent helper tool and is not affiliated with, authorized, or endorsed by the official government <a href="https://fuelpass.gov.lk/" target="_blank" rel="noopener noreferrer">Fuel Pass Site</a>.</span>
      </div>
    </footer>
  </main>

  <!-- External Libraries & Core Logic -->
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
  <script src="app.js?v=<?= filemtime('app.js') ?>"></script>
</body>
</html>
