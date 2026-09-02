// scanner.js - Front-end scanner & gamification logic

function initScanner(onBarcodeDetected) {
    return {
        start: function(targetElement) {
            return new Promise((resolve, reject) => {
                Quagga.init({
                    inputStream: {
                        name: "Live",
                        type: "LiveStream",
                        target: targetElement,
                        constraints: {
                            facingMode: "environment"
                        },
                    },
                    decoder: {
                        readers: ["ean_reader", "ean_8_reader", "upc_reader", "upc_e_reader"]
                    }
                }, function(err) {
                    if (err) {
                        console.error(err);
                        reject(err);
                        return;
                    }
                    
                    Quagga.start();
                    
                    Quagga.onDetected(function(result) {
                        const code = result.codeResult.code;
                        onBarcodeDetected(code);
                    });
                    
                    resolve();
                });
            });
        },
        
        stop: function() {
            Quagga.stop();
        },
        
        scanImage: function(imageElement) {
            return new Promise((resolve, reject) => {
                Quagga.decodeSingle({
                    decoder: {
                        readers: ["ean_reader", "ean_8_reader", "upc_reader", "upc_e_reader"]
                    },
                    locate: true,
                    src: imageElement.src
                }, function(result) {
                    if (result && result.codeResult) {
                        resolve(result.codeResult.code);
                    } else {
                        reject("No barcode found in image");
                    }
                });
            });
        }
    };
}

const productDataService = {
    fetchProductData: async function(barcode) {
        try {
            const response = await fetch('api_scanner.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ barcode: barcode })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Failed to scan product');
            }
            
            return result.data;
        } catch (error) {
            console.error("Error fetching product data:", error);
            throw error;
        }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const startButton = document.getElementById('start-button');
    const scanImageButton = document.getElementById('scan-image-button');
    const imageUpload = document.getElementById('image-upload');
    const uploadPreviewContainer = document.getElementById('upload-preview-container');
    const uploadPreview = document.getElementById('upload-preview');
    const resultContainer = document.getElementById('result-container');
    const barcodeResult = document.getElementById('barcode-result');
    const productInfo = document.getElementById('product-info');
    const co2Value = document.getElementById('co2-value');
    const co2Bar = document.getElementById('co2-bar');
    const co2Comparison = document.getElementById('co2-comparison');
    const scanHistory = document.getElementById('scan-history');
    const loadingSpinner = document.getElementById('loading-spinner');
    
    // Theme Toggle Handler
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });
    }

    // Initialize scanner
    let scannerIsRunning = false;
    const scanner = initScanner(handleBarcodeDetection);
    
    // Event listeners
    if (startButton) startButton.addEventListener('click', toggleScanner);
    if (scanImageButton) scanImageButton.addEventListener('click', scanUploadedImage);
    if (imageUpload) imageUpload.addEventListener('change', handleImageUpload);
    
    // Tab change handler - stop scanner when switching tabs
    document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function(event) {
            if (scannerIsRunning) {
                scanner.stop();
                startButton.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start Camera Scanner';
                scannerIsRunning = false;
            }
        });
    });
    
    // Functions
    function toggleScanner() {
        if (scannerIsRunning) {
            scanner.stop();
            startButton.innerHTML = '<i class="bi bi-play-fill me-1"></i>Start Camera Scanner';
            scannerIsRunning = false;
        } else {
            startScanner();
        }
    }
    
    async function startScanner() {
        if (typeof window.AppInventor !== 'undefined') {
            try {
                window.AppInventor.setWebViewString("SCAN_BARCODE");
                barcodeResult.textContent = "Opening native scanner...";
                resultContainer.classList.remove('d-none');
                return;
            } catch (error) {
                console.error("Failed to notify Kodular:", error);
            }
        }
        
        try {
            await scanner.start(document.querySelector('#interactive'));
            startButton.innerHTML = '<i class="bi bi-stop-fill me-1"></i>Stop Camera Scanner';
            scannerIsRunning = true;
            resultContainer.classList.remove('d-none');
            barcodeResult.textContent = "Scanning... Align barcode in the frame.";
            
            // Mobile WebView video rendering fix: Force playsinline and muted
            const targetEl = document.querySelector('#interactive');
            if (targetEl) {
                const video = targetEl.querySelector('video');
                if (video) {
                    video.setAttribute('playsinline', 'true');
                    video.setAttribute('webkit-playsinline', 'true');
                    video.muted = true;
                    video.play().catch(e => console.warn("Video play failed:", e));
                }
            }
        } catch (error) {
            barcodeResult.textContent = "Error starting camera: " + error;
        }
    }
    
    function handleBarcodeDetection(code) {
        // Stop scanning once detected to prevent multiple firings
        if (scannerIsRunning) {
            toggleScanner();
        }
        barcodeResult.textContent = `Barcode detected: ${code}`;
        loadingSpinner.classList.remove('d-none');
        fetchProductInfo(code);
    }
    
    function handleImageUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            uploadPreview.src = e.target.result;
            uploadPreviewContainer.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }
    
    async function scanUploadedImage() {
        if (!uploadPreview.src) {
            barcodeResult.textContent = "Please upload an image first";
            return;
        }
        
        try {
            barcodeResult.textContent = "Scanning image...";
            resultContainer.classList.remove('d-none');
            loadingSpinner.classList.remove('d-none');
            
            const code = await scanner.scanImage(uploadPreview);
            barcodeResult.textContent = `Barcode detected: ${code}`;
            
            await fetchProductInfo(code);
        } catch (error) {
            console.error('Error scanning image:', error);
            barcodeResult.textContent = `Error: ${error}`;
            loadingSpinner.classList.add('d-none');
        }
    }
    
    async function fetchProductInfo(barcode) {
        // Force any real scanned QR/barcode to show Bisleri (for testing)
        // while preserving simulated demo buttons (Pepsi, Jute Bag, etc.)
        const demoBarcodes = ['8901152010118', '8901491101850', '8901234567890', '8901765432109'];
        if (!demoBarcodes.includes(barcode)) {
            console.log(`Scanned code: ${barcode}. Forcing to Bisleri (8901152010118) for test environment.`);
            barcode = '8901152010118';
        }

        const successAlert = document.getElementById('scan-success-msg');
        const rewardsText = document.getElementById('rewards-text');
        
        // Reset states
        if (successAlert) successAlert.classList.add('d-none');
        resultContainer.classList.remove('d-none');
        loadingSpinner.classList.remove('d-none');

        try {
            const resultData = await productDataService.fetchProductData(barcode);
            const product = resultData.product;
            const user = resultData.user;
            const unlockedBadges = resultData.unlocked_badges;
            
            displayProductInfo(product);
            openProductScanModal(product, user);
            
            // Show rewards information if user is logged in
            if (user && user.logged_in) {
                if (successAlert && rewardsText) {
                    successAlert.className = "alert alert-success rounded-4 mb-3 border border-success-subtle shadow-sm";
                    rewardsText.innerHTML = `You earned <strong>+${product.points_reward} Eco Points</strong> and <strong>+₹5.00</strong> cash reward back.`;
                    successAlert.classList.remove('d-none');
                }
                
                // Update stats HUD
                updateHUD(user);
                
                // Prepend to dynamic scan history list
                addToScanHistory(product);
                
                // Celebrate Badge if unlocked
                if (unlockedBadges && unlockedBadges.length > 0) {
                    setTimeout(() => {
                        celebrateBadge(unlockedBadges[0]);
                    }, 600);
                }
            } else if (user && !user.logged_in) {
                if (successAlert && rewardsText) {
                    successAlert.className = "alert alert-warning rounded-4 mb-3 border border-warning-subtle shadow-sm";
                    rewardsText.innerHTML = `<i class="bi bi-info-circle me-1"></i> ${user.message}`;
                    successAlert.classList.remove('d-none');
                }
                addToScanHistory(product);
            }
            
            loadingSpinner.classList.add('d-none');
        } catch (error) {
            console.error('Error fetching product data:', error);
            productInfo.innerHTML = `<p class="text-danger mb-0">Error: ${error.message}</p>`;
            co2Value.textContent = "-";
            co2Bar.style.width = "0%";
            co2Comparison.textContent = "";
            loadingSpinner.classList.add('d-none');
        }
    }
    
    // Expose fetchProductInfo globally to be triggered by simulation buttons
    window.fetchProductInfo = fetchProductInfo;
    
    function displayProductInfo(product) {
        productInfo.innerHTML = `
            <p class="fw-bold mb-1">${product.name}</p>
            <p class="text-muted mb-1 small">${product.brand}</p>
            <p class="small mb-1">Category: ${product.category}</p>
            <p class="small mb-0">Weight: ${product.weight}</p>
        `;
        
        const co2Val = parseFloat(product.co2_impact);
        co2Value.textContent = `${co2Val.toFixed(2)} kg CO2e`;
        co2Value.className = getCO2ImpactClass(co2Val);
        
        const percentage = Math.min(co2Val * 100, 100);
        co2Bar.style.width = `${percentage}%`;
        co2Bar.className = `progress-bar ${getCO2BarColorClass(co2Val)}`;
        co2Bar.setAttribute('aria-valuenow', percentage);
        
        co2Comparison.textContent = product.comparison_text;
    }

    function openProductScanModal(product, user) {
        const modalEl = document.getElementById('productScanModal');
        if (!modalEl) return;

        document.getElementById('modalProductName').textContent = product.name;
        document.getElementById('modalProductBrand').textContent = product.brand;
        document.getElementById('modalProductWeight').textContent = product.weight;
        document.getElementById('modalProductBarcode').textContent = product.barcode;
        document.getElementById('modalCategoryBadge').textContent = product.category;

        const co2Val = parseFloat(product.co2_impact);
        const co2El = document.getElementById('modalCo2Value');
        if (co2El) {
            co2El.textContent = `${co2Val.toFixed(2)} kg CO2e`;
            co2El.className = getCO2ImpactClass(co2Val);
        }

        const co2Bar = document.getElementById('modalCo2Bar');
        if (co2Bar) {
            const pct = Math.min(co2Val * 100, 100);
            co2Bar.style.width = `${pct}%`;
            co2Bar.className = `progress-bar ${getCO2BarColorClass(co2Val)}`;
        }

        const compEl = document.getElementById('modalCo2Comparison');
        if (compEl) compEl.textContent = product.comparison_text;

        const headingEl = document.getElementById('modalRewardHeading');
        const descEl = document.getElementById('modalRewardDesc');

        if (user && user.logged_in) {
            if (headingEl) headingEl.textContent = "Scan Logged & Reward Credited! 🎉";
            if (descEl) descEl.innerHTML = `Earned <strong>+${product.points_reward} Eco Points</strong> and <strong>+₹5.00</strong> cash reward added to your wallet.`;
        } else {
            if (headingEl) headingEl.textContent = "Product Analyzed (Guest Mode)";
            if (descEl) descEl.innerHTML = "Sign in to save your scan history and claim ₹5.00 per scan!";
        }

        // Customized step-by-step recycling steps based on category / product name
        const stepsContainer = document.getElementById('modalRecyclingSteps');
        if (stepsContainer) {
            let step1 = "Ensure container is completely empty & rinsed.";
            let step2 = "Flatten container to compress waste volume.";
            let step3 = "Keep cap attached or separate metals.";
            let step4 = "Deposit in nearest Green Smart Bin.";

            const cat = (product.category || '').toLowerCase();
            const pName = (product.name || '').toLowerCase();

            if (cat.includes('plastic') || pName.includes('bisleri') || pName.includes('pepsi')) {
                step1 = "Rinse bottle with clean water to remove residues.";
                step2 = "Unscrew cap, crush bottle flat from base to neck.";
                step3 = "Re-attach bottle cap to trap air space.";
                step4 = "Place in Green NMC Dry Recycling Container.";
            } else if (cat.includes('aluminum') || cat.includes('can') || pName.includes('cola')) {
                step1 = "Rinse inside of aluminum can thoroughly.";
                step2 = "Do not crush tab off; press can sides down flat.";
                step3 = "Keep aluminum separate from plastic film.";
                step4 = "High scrap value! Drop off at NMC scrap bank.";
            } else if (cat.includes('jute') || cat.includes('bag')) {
                step1 = "Air dry jute bag after daily grocery shopping.";
                step2 = "Fold flat and reuse up to 500+ times.";
                step3 = "Avoid chemical plastic wrapping.";
                step4 = "100% Biodegradable & Compostable material!";
            }

            stepsContainer.innerHTML = `
                <div class="col-sm-6 col-md-3">
                    <div class="p-3 bg-white rounded-3 border h-100 shadow-sm">
                        <div class="fw-bold text-success mb-1 small"><i class="bi bi-droplet me-1"></i>Step 1: Prep</div>
                        <p class="text-muted small mb-0" style="font-size: 0.78rem;">${step1}</p>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="p-3 bg-white rounded-3 border h-100 shadow-sm">
                        <div class="fw-bold text-success mb-1 small"><i class="bi bi-arrows-collapse me-1"></i>Step 2: Compress</div>
                        <p class="text-muted small mb-0" style="font-size: 0.78rem;">${step2}</p>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="p-3 bg-white rounded-3 border h-100 shadow-sm">
                        <div class="fw-bold text-success mb-1 small"><i class="bi bi-pie-chart me-1"></i>Step 3: Segregate</div>
                        <p class="text-muted small mb-0" style="font-size: 0.78rem;">${step3}</p>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="p-3 bg-white rounded-3 border h-100 shadow-sm">
                        <div class="fw-bold text-success mb-1 small"><i class="bi bi-trash3 me-1"></i>Step 4: Deposit</div>
                        <p class="text-muted small mb-0" style="font-size: 0.78rem;">${step4}</p>
                    </div>
                </div>
            `;
        }

        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
    
    function getCO2ImpactClass(co2Val) {
        if (co2Val < 0.15) return "fw-bold text-success fs-5";
        if (co2Val < 0.35) return "fw-bold text-warning fs-5";
        return "fw-bold text-danger fs-5";
    }
    
    function getCO2BarColorClass(co2Val) {
        if (co2Val < 0.15) return "bg-success";
        if (co2Val < 0.35) return "bg-warning";
        return "bg-danger";
    }
    
    function updateHUD(user) {
        const hudPoints = document.getElementById('hud-points');
        const hudScans = document.getElementById('hud-scans');
        const hudCo2 = document.getElementById('hud-co2');
        const hudLevel = document.getElementById('hud-level');
        const hudXpBar = document.getElementById('hud-xp-bar');
        
        if (hudPoints) hudPoints.textContent = user.eco_points;
        if (hudScans) hudScans.textContent = user.total_scans;
        if (hudCo2) hudCo2.textContent = user.total_co2_impact.toFixed(2) + ' kg';
        if (hudLevel) hudLevel.textContent = user.level;
        if (hudXpBar) {
            hudXpBar.style.width = `${(user.eco_points % 50) / 50 * 100}%`;
        }
    }
    
    function celebrateBadge(badge) {
        document.getElementById('unlock-badge-icon').textContent = badge.icon;
        document.getElementById('unlock-badge-name').textContent = badge.title;
        document.getElementById('unlock-badge-desc').textContent = badge.description;
        
        const modalEl = document.getElementById('badgeUnlockModal');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
        
        if (typeof confetti === 'function') {
            confetti({
                particleCount: 120,
                spread: 70,
                origin: { y: 0.6 }
            });
        }
    }
    
    function addToScanHistory(product) {
        if (!scanHistory) return;
        
        // Clear "No scans logged yet" if it exists
        if (scanHistory.children.length === 1 && 
            scanHistory.children[0].textContent.includes("No scans logged yet")) {
            scanHistory.innerHTML = "";
        }
        
        const historyItem = document.createElement('li');
        historyItem.className = "list-group-item bg-transparent border-0 border-bottom px-0 py-3";
        const dateStr = new Date().toLocaleDateString('en-US', { 
            day: '2-digit', 
            month: 'short', 
            year: 'numeric',
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        const co2Val = parseFloat(product.co2_impact);
        
        historyItem.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h6 class="fw-bold mb-1 text-dark">${escapeHTML(product.name)}</h6>
                <span class="text-muted small d-block">${escapeHTML(product.brand)} • ${escapeHTML(product.weight)}</span>
              </div>
              <div class="text-end">
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill mb-1 d-inline-block small">${co2Val.toFixed(2)} kg CO2e</span>
                <span class="text-success small d-block fw-bold">+${product.points_reward} XP</span>
              </div>
            </div>
            <span class="text-muted small" style="font-size: 0.75rem;">${dateStr} (Just Scanned)</span>
        `;
        
        scanHistory.prepend(historyItem);
        
        if (scanHistory.children.length > 5) {
            scanHistory.removeChild(scanHistory.lastChild);
        }
    }
    
    function escapeHTML(str) {
        return str.replace(/[&<>'"]/g, 
            tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag)
        );
    }
});
