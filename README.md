# Fuel Pass to Wallet

A simple tool to convert your Sri Lankan National Fuel Pass QR code into a native Apple Wallet digital pass (.pkpass). All the processing happens on the fly, and nothing is ever saved or stored on a server.

## Prerequisites

To run this locally, you will need:

* A web server running PHP 8.0 or newer (with openssl and zip extensions enabled).
* An active Apple Developer Account, since Apple requires all Wallet passes to be signed.

## Certificate Setup

Apple is strict about Wallet security. Therefore, every .pkpass file needs to be cryptographically signed with a developer certificate. 

To make this work, you need to place three files inside the `certificates/` directory:

* `WWDR.pem`: Apple's Worldwide Developer Relations certificate (the G4 version is recommended).
* `signerCert.pem`: Your Pass Type ID certificate in PEM format.
* `signerKey.pem`: The private key associated with your certificate in PEM format.

Note: I've added 3 dummy files in the repo. They won't work for actual pkpass generation.

### Certificate Generation Guide

If you need a step-by-step guide on how to generate, export, and convert Apple certificates, this is a really helpful resource:
[alexandercerutti/passkit-generator Wiki: Generating Certificates](https://github.com/alexandercerutti/passkit-generator/wiki/Generating-Certificates)

#### Quick OpenSSL Cheatsheet
If you export your certificate and private key together as a single `.p12` file (like `Certificates.p12`) from Keychain Access on macOS, you can split them into the required PEM files by running these commands in your terminal:

```bash
# Extract the Pass Certificate PEM
openssl pkcs12 -in Certificates.p12 -clcerts -nokeys -out signerCert.pem

# Extract the Private Key PEM (unencrypted)
openssl pkcs12 -in Certificates.p12 -nocerts -nodes -out signerKey.pem

# Convert Apple WWDR certificate (.cer) to PEM
openssl x509 -inform DER -in AppleWWDRCAG4.cer -out WWDR.pem
```

## Running Locally

1. Clone the repository:
   ```bash
   git clone https://github.com/prabch/Fuel-Pass-to-Wallet.git
   cd Fuel-Pass-to-Wallet
   ```
2. Put your certificates in the `/certificates` directory (see the Certificate Setup section above).
3. Start a local PHP server from the `app` directory:
   ```bash
   cd app
   php -S localhost:8000
   ```
4. Open your browser and go to `http://localhost:8000`.

## Tech Stack

I purposely went old school here with basic PHP and vanilla JS. I didn't want to deal with React, build steps, or any other modern setup for a simple tool like this. Just plain code that works, loads fast, and has zero dependencies.

## License

This project is open-source and licensed under the GNU General Public License v3 (GPL-3.0). You can check the [LICENSE](file:///Users/prabhashwara/Documents/Pet-Projects/Fuel-Pass-to-Wallet/LICENSE) file in the root directory for details.

---

Disclaimer: This is an independent helper tool and is not affiliated with, authorized, or endorsed by the official government Fuel Pass Site.
