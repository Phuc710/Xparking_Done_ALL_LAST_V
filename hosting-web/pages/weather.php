<?php
// pages/weather.php - Weather with starry sky background
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thời tiết - XPARKING</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        html,body{height:100%;margin:0;overflow-x:hidden}
        body{font-family:system-ui,'Segoe UI',Roboto,sans-serif}

        /* Starry sky background */
        #sky{
            position:fixed;inset:0;width:100%;height:100%;display:block;z-index:0;
            background:linear-gradient(180deg,#050a14 0%, #070d1d 50%, #030710 100%);
        }

        /* Weather overlay effects */
        .weather-overlay {
            position:fixed;inset:0;z-index:1;pointer-events:none;
            transition:opacity 1s ease;
        }

        .weather-overlay.rain {
            background:linear-gradient(180deg,rgba(0,50,100,0.3) 0%,rgba(0,20,50,0.2) 100%);
        }

        .weather-overlay.clouds {
            background:linear-gradient(180deg,rgba(100,100,120,0.2) 0%,transparent 100%);
        }

        .weather-overlay.clear {
            background:linear-gradient(180deg,rgba(255,200,100,0.1) 0%,transparent 100%);
        }

        /* UI Container */
        .ui {
            position:relative;z-index:10;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem;
        }

        .weather-card {
            background:rgba(255,255,255,0.06);
            backdrop-filter:blur(12px);
            padding:2.5rem;
            border-radius:24px;
            color:#cfe8ff;
            border:1px solid rgba(255,255,255,0.1);
            box-shadow:0 8px 32px rgba(2,6,23,0.8);
            max-width:500px;
            width:100%;
        }

        .back-btn {
            position:fixed;top:2rem;left:2rem;z-index:100;
            background:rgba(255,255,255,0.08);
            backdrop-filter:blur(8px);
            border:1px solid rgba(255,255,255,0.1);
            color:#cfe8ff;
            padding:0.8rem 1.5rem;
            border-radius:50px;
            cursor:pointer;
            transition:all 0.3s;
        }

        .back-btn:hover {
            background:rgba(255,255,255,0.15);
            transform:translateY(-2px);
        }

        .search-box {
            position:relative;margin-bottom:2rem;
        }

        .search-input {
            width:100%;
            padding:1rem 3rem 1rem 1.5rem;
            border:1px solid rgba(255,255,255,0.2);
            border-radius:50px;
            background:rgba(255,255,255,0.08);
            color:#fff;
            font-size:1rem;
        }

        .search-input::placeholder {
            color:rgba(207,232,255,0.5);
        }

        .search-btn {
            position:absolute;right:10px;top:50%;transform:translateY(-50%);
            background:rgba(102,126,234,0.8);
            border:none;
            color:white;
            width:40px;height:40px;
            border-radius:50%;
            cursor:pointer;
            transition:all 0.3s;
        }

        .search-btn:hover {
            background:rgba(102,126,234,1);
            transform:translateY(-50%) scale(1.1);
        }

        .suggestions {
            position:absolute;top:100%;left:0;right:0;
            background:rgba(255,255,255,0.95);
            backdrop-filter:blur(10px);
            border-radius:15px;
            box-shadow:0 5px 20px rgba(0,0,0,0.3);
            margin-top:0.5rem;
            max-height:300px;
            overflow-y:auto;
            display:none;
            z-index:1000;
        }

        .suggestion-item {
            padding:1rem 1.5rem;
            cursor:pointer;
            border-bottom:1px solid rgba(0,0,0,0.05);
            color:#333;
            transition:background 0.2s;
        }

        .suggestion-item:hover {
            background:rgba(102,126,234,0.1);
        }

        .weather-icon {
            font-size:5rem;
            margin:1.5rem 0;
            text-align:center;
            filter:drop-shadow(0 4px 8px rgba(0,0,0,0.3));
        }

        .temperature {
            font-size:3.5rem;
            font-weight:700;
            text-align:center;
            margin-bottom:0.5rem;
            text-shadow:0 2px 8px rgba(0,0,0,0.3);
        }

        .description {
            font-size:1.3rem;
            text-align:center;
            color:rgba(207,232,255,0.8);
            margin-bottom:2rem;
            text-transform:capitalize;
        }

        .location {
            font-size:1.8rem;
            font-weight:600;
            text-align:center;
            margin-bottom:1rem;
            color:#fff;
        }

        .weather-details {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:1rem;
            margin-top:2rem;
        }

        .detail-item {
            background:rgba(255,255,255,0.05);
            padding:1rem;
            border-radius:12px;
            text-align:center;
            border:1px solid rgba(255,255,255,0.08);
        }

        .detail-item i {
            font-size:1.3rem;
            margin-bottom:0.5rem;
            color:rgba(102,126,234,0.8);
        }

        .detail-label {
            font-size:0.85rem;
            color:rgba(207,232,255,0.6);
            margin-bottom:0.3rem;
        }

        .detail-value {
            font-size:1.2rem;
            font-weight:600;
            color:#fff;
        }

        .loading {
            display:none;
            text-align:center;
        }

        .spinner {
            border:4px solid rgba(255,255,255,0.1);
            border-top:4px solid rgba(102,126,234,0.8);
            border-radius:50%;
            width:50px;
            height:50px;
            animation:spin 1s linear infinite;
            margin:2rem auto;
        }

        @keyframes spin {
            to{transform:rotate(360deg);}
        }

        /* Rain animation */
        .rain-drop {
            position:absolute;
            width:2px;
            height:60px;
            background:linear-gradient(transparent,rgba(255,255,255,0.5));
            animation:fall linear infinite;
            pointer-events:none;
        }

        @keyframes fall {
            to{transform:translateY(100vh);}
        }

        /* Snow animation */
        .snowflake {
            position:absolute;
            color:rgba(255,255,255,0.8);
            font-size:1.2em;
            animation:fall-snow linear infinite;
            pointer-events:none;
        }

        @keyframes fall-snow {
            to{transform:translateY(100vh) rotate(360deg);}
        }

        @media (max-width:576px) {
            .weather-card{padding:2rem 1.5rem;}
            .temperature{font-size:3rem;}
            .weather-icon{font-size:4rem;}
        }
    </style>
</head>
<body>
    <!-- Starry sky canvas -->
    <canvas id="sky"></canvas>
    
    <!-- Weather overlay for effects -->
    <div class="weather-overlay" id="weatherOverlay"></div>
    <div id="weatherAnimation" style="position:fixed;inset:0;z-index:5;pointer-events:none;"></div>
    
    <!-- Back button -->
    <button class="back-btn" onclick="window.location.href='../index.php'">
        <i class="fas fa-arrow-left me-2"></i>Quay lại
    </button>
    
    <div class="ui">
        <div class="weather-card">
            <!-- Search -->
            <div class="search-box">
                <input type="text" class="search-input" id="searchInput"
                       placeholder="Tìm kiếm (VD: TPHCM, Củ Chi, Hà Nội...)" autocomplete="off">
                <button class="search-btn" onclick="searchWeather()">
                    <i class="fas fa-search"></i>
                </button>
                <div class="suggestions" id="suggestions"></div>
            </div>
            
            <!-- Loading -->
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Đang tải...</p>
            </div>
            
            <!-- Weather info -->
            <div id="weatherInfo" style="display:none;">
                <div class="location" id="location">--</div>
                <div class="weather-icon" id="weatherIcon">
                    <i class="fas fa-sun"></i>
                </div>
                <div class="temperature" id="temperature">--°C</div>
                <div class="description" id="description">--</div>
                
                <div class="weather-details">
                    <div class="detail-item">
                        <i class="fas fa-tint"></i>
                        <div class="detail-label">Độ ẩm</div>
                        <div class="detail-value" id="humidity">--%</div>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-wind"></i>
                        <div class="detail-label">Gió</div>
                        <div class="detail-value" id="wind">-- km/h</div>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-eye"></i>
                        <div class="detail-label">Tầm nhìn</div>
                        <div class="detail-value" id="visibility">-- km</div>
                    </div>
                    <div class="detail-item">
                        <i class="fas fa-compress-arrows-alt"></i>
                        <div class="detail-label">Áp suất</div>
                        <div class="detail-value" id="pressure">-- hPa</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Starry sky configuration
        const CONFIG = {
            starCount: Math.round((window.innerWidth * window.innerHeight) / 9000),
            minRadius: 0.4,
            maxRadius: 1.8,
            maxSpeed: 0.05,
            twinkleSpeed: 0.0025,
            colorBias: { r: 200, g: 220, b: 255 }
        };

        const canvas = document.getElementById('sky');
        const ctx = canvas.getContext('2d');
        let DPR = Math.max(1, window.devicePixelRatio || 1);

        function resize(){
            DPR = Math.max(1, window.devicePixelRatio || 1);
            canvas.width = Math.floor(window.innerWidth * DPR);
            canvas.height = Math.floor(window.innerHeight * DPR);
            canvas.style.width = window.innerWidth + 'px';
            canvas.style.height = window.innerHeight + 'px';
            ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        }

        window.addEventListener('resize', ()=>{
            resize();
            initStars();
        });

        class Star{
            constructor(w,h){this.reset(w,h);}
            reset(w,h){
                this.x = Math.random()*w;
                this.y = Math.random()*h;
                this.r = CONFIG.minRadius + Math.random()*(CONFIG.maxRadius-CONFIG.minRadius);
                const angle = Math.random()*Math.PI*2;
                const speed = Math.random()*CONFIG.maxSpeed;
                this.vx = Math.cos(angle)*speed;
                this.vy = Math.sin(angle)*speed*0.5;
                this.baseAlpha = 0.4 + Math.random()*0.6;
                this.alpha = this.baseAlpha;
                this.twinklePhase = Math.random()*Math.PI*2;
                const c = CONFIG.colorBias;
                const shift = (Math.random()*30)-15;
                this.color = `rgba(${Math.min(255,c.r+shift)},${Math.min(255,c.g+shift)},${Math.min(255,c.b+shift)},`;
            }
            update(dt,w,h){
                this.x += this.vx * dt;
                this.y += this.vy * dt;
                if(this.x < -10) this.x = w + 10;
                if(this.x > w + 10) this.x = -10;
                if(this.y < -10) this.y = h + 10;
                if(this.y > h + 10) this.y = -10;
                this.twinklePhase += CONFIG.twinkleSpeed * dt;
                this.alpha = this.baseAlpha + Math.sin(this.twinklePhase)*0.25 + (Math.random()*0.06 - 0.03);
                if(this.alpha < 0) this.alpha = 0;
                if(this.alpha > 1) this.alpha = 1;
            }
            draw(ctx){
                const grd = ctx.createRadialGradient(this.x, this.y, 0, this.x, this.y, this.r*6);
                grd.addColorStop(0, this.color + (this.alpha*0.45) + ')');
                grd.addColorStop(0.3, this.color + (this.alpha*0.18) + ')');
                grd.addColorStop(1, this.color + '0)');
                ctx.beginPath();
                ctx.fillStyle = grd;
                ctx.arc(this.x, this.y, this.r*6, 0, Math.PI*2);
                ctx.fill();

                ctx.beginPath();
                ctx.fillStyle = this.color + this.alpha + ')';
                ctx.arc(this.x, this.y, this.r, 0, Math.PI*2);
                ctx.fill();
            }
        }

        let stars = [];
        let last = performance.now();

        function initStars(){
            const w = canvas.width / DPR;
            const h = canvas.height / DPR;
            const count = Math.max(20, CONFIG.starCount);
            stars = [];
            for(let i=0;i<count;i++) stars.push(new Star(w,h));
        }

        function drawNebula(ctx,w,h){
            const g = ctx.createLinearGradient(0, h*0.15, 0, h);
            g.addColorStop(0, 'rgba(14,28,60,0.06)');
            g.addColorStop(0.5, 'rgba(6,12,32,0.02)');
            g.addColorStop(1, 'rgba(6,12,32,0.2)');
            ctx.fillStyle = g;
            ctx.fillRect(0,0,w,h);
        }

        function frame(now){
            const dt = Math.min(40, now - last);
            last = now;
            const w = canvas.width / DPR;
            const h = canvas.height / DPR;

            const bg = ctx.createLinearGradient(0,0,0,h);
            bg.addColorStop(0,'#050a14');
            bg.addColorStop(0.5,'#070d1d');
            bg.addColorStop(1,'#030710');
            ctx.fillStyle = bg;
            ctx.fillRect(0,0,w,h);

            drawNebula(ctx,w,h);

            for(const s of stars){
                s.update(dt, w, h);
                s.draw(ctx);
            }

            requestAnimationFrame(frame);
        }

        // Vietnam cities with smart search
        const vietnamCities = [
            {name: 'TP. Hồ Chí Minh', aliases: ['ho chi minh', 'hcm', 'tphcm', 'saigon', 'sai gon'], lat: 10.8231, lon: 106.6297},
            {name: 'Hà Nội', aliases: ['ha noi', 'hanoi'], lat: 21.0285, lon: 105.8542},
            {name: 'Đà Nẵng', aliases: ['da nang', 'danang'], lat: 16.0544, lon: 108.2022},
            {name: 'Cần Thơ', aliases: ['can tho', 'cantho'], lat: 10.0452, lon: 105.7469},
            {name: 'Hải Phòng', aliases: ['hai phong', 'haiphong'], lat: 20.8449, lon: 106.6881},
            {name: 'Củ Chi, HCM', aliases: ['cu chi', 'củ chi'], lat: 10.9745, lon: 106.4937},
            {name: 'Thủ Đức, HCM', aliases: ['thu duc', 'thủ đức'], lat: 10.8509, lon: 106.7714},
            {name: 'Bình Thạnh, HCM', aliases: ['binh thanh', 'bình thạnh'], lat: 10.8142, lon: 106.7045},
            {name: 'Tân Bình, HCM', aliases: ['tan binh', 'tân bình'], lat: 10.8006, lon: 106.6528},
            {name: 'Gò Vấp, HCM', aliases: ['go vap', 'gò vấp'], lat: 10.8376, lon: 106.6559},
            {name: 'Vũng Tàu', aliases: ['vung tau', 'vũng tàu'], lat: 10.4113, lon: 107.1362},
            {name: 'Nha Trang', aliases: ['nha trang'], lat: 12.2388, lon: 109.1967},
            {name: 'Đà Lạt', aliases: ['da lat', 'đà lạt', 'dalat'], lat: 11.9404, lon: 108.4583},
            {name: 'Huế', aliases: ['hue', 'huế'], lat: 16.4637, lon: 107.5909},
            {name: 'Phú Quốc', aliases: ['phu quoc', 'phú quốc'], lat: 10.2275, lon: 103.9670},
            {name: 'Phan Thiết', aliases: ['phan thiet', 'phan thiết', 'mui ne', 'mũi né'], lat: 10.9333, lon: 108.1000},
            {name: 'Sa Pa', aliases: ['sapa', 'sa pa'], lat: 22.3364, lon: 103.8438},
            {name: 'Hạ Long', aliases: ['ha long', 'hạ long', 'halong'], lat: 20.9517, lon: 107.0432}
        ];

        function removeAccents(str) {
            return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        }

        function searchCities(query) {
            if (!query || query.length < 2) return [];
            const normalized = removeAccents(query);
            return vietnamCities.filter(city => {
                if (removeAccents(city.name).includes(normalized)) return true;
                return city.aliases.some(alias => 
                    removeAccents(alias).includes(normalized) ||
                    normalized.includes(removeAccents(alias))
                );
            }).slice(0, 5);
        }

        // Search input handler
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const query = e.target.value;
            const suggestionsDiv = document.getElementById('suggestions');
            
            if (query.length < 2) {
                suggestionsDiv.style.display = 'none';
                return;
            }
            
            const results = searchCities(query);
            
            if (results.length > 0) {
                suggestionsDiv.innerHTML = results.map(city => 
                    `<div class="suggestion-item" onclick="selectCity('${city.name}', ${city.lat}, ${city.lon})">
                        <i class="fas fa-map-marker-alt me-2"></i>${city.name}
                    </div>`
                ).join('');
                suggestionsDiv.style.display = 'block';
            } else {
                suggestionsDiv.style.display = 'none';
            }
        });

        function selectCity(name, lat, lon) {
            document.getElementById('searchInput').value = name;
            document.getElementById('suggestions').style.display = 'none';
            getWeatherByCoords(lat, lon, name);
        }

        function searchWeather() {
            const query = document.getElementById('searchInput').value;
            if (!query) return;
            
            const results = searchCities(query);
            if (results.length > 0) {
                const city = results[0];
                getWeatherByCoords(city.lat, city.lon, city.name);
            }
        }

        function getWeatherByCoords(lat, lon, cityName) {
            showLoading();
            const apiKey = 'bd5e378503939ddaee76f12ad7a97608';
            const url = `https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lon}&appid=${apiKey}&units=metric&lang=vi`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.cod === 200) {
                        displayWeather(data, cityName);
                    } else {
                        alert('Không thể lấy dữ liệu!');
                        hideLoading();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Lỗi kết nối!');
                    hideLoading();
                });
        }

        function displayWeather(data, displayName = null) {
            hideLoading();
            
            const location = displayName || data.name;
            const temp = Math.round(data.main.temp);
            const description = data.weather[0].description;
            const humidity = data.main.humidity;
            const windSpeed = Math.round(data.wind.speed * 3.6);
            const visibility = Math.round(data.visibility / 1000);
            const pressure = data.main.pressure;
            const weatherMain = data.weather[0].main.toLowerCase();
            
            document.getElementById('location').textContent = location;
            document.getElementById('temperature').textContent = temp + '°C';
            document.getElementById('description').textContent = description;
            document.getElementById('humidity').textContent = humidity + '%';
            document.getElementById('wind').textContent = windSpeed + ' km/h';
            document.getElementById('visibility').textContent = visibility + ' km';
            document.getElementById('pressure').textContent = pressure + ' hPa';
            
            updateWeatherIcon(weatherMain);
            updateWeatherEffects(weatherMain);
            
            document.getElementById('weatherInfo').style.display = 'block';
        }

        function updateWeatherIcon(weatherMain) {
            const iconEl = document.getElementById('weatherIcon');
            const icons = {
                'clear': 'fa-sun',
                'clouds': 'fa-cloud',
                'rain': 'fa-cloud-rain',
                'drizzle': 'fa-cloud-rain',
                'thunderstorm': 'fa-bolt',
                'snow': 'fa-snowflake',
                'mist': 'fa-smog',
                'haze': 'fa-smog',
                'fog': 'fa-smog'
            };
            iconEl.innerHTML = `<i class="fas ${icons[weatherMain] || 'fa-cloud-sun'}"></i>`;
        }

        function updateWeatherEffects(weatherMain) {
            const overlay = document.getElementById('weatherOverlay');
            const animationDiv = document.getElementById('weatherAnimation');
            
            // Clear animations
            animationDiv.innerHTML = '';
            overlay.className = 'weather-overlay';
            
            // Add overlay effect
            overlay.classList.add(weatherMain);
            
            // Create animations
            switch(weatherMain) {
                case 'rain':
                case 'drizzle':
                    createRain(100);
                    break;
                case 'thunderstorm':
                    createRain(150);
                    createThunder();
                    break;
                case 'snow':
                    createSnow(80);
                    break;
            }
        }

        function createRain(count) {
            const container = document.getElementById('weatherAnimation');
            for (let i = 0; i < count; i++) {
                const drop = document.createElement('div');
                drop.className = 'rain-drop';
                drop.style.left = Math.random() * 100 + '%';
                drop.style.animationDuration = (Math.random() * 0.5 + 0.5) + 's';
                drop.style.animationDelay = Math.random() * 2 + 's';
                container.appendChild(drop);
            }
        }

        function createSnow(count) {
            const container = document.getElementById('weatherAnimation');
            for (let i = 0; i < count; i++) {
                const flake = document.createElement('div');
                flake.className = 'snowflake';
                flake.innerHTML = '❄';
                flake.style.left = Math.random() * 100 + '%';
                flake.style.fontSize = (Math.random() * 1 + 0.8) + 'em';
                flake.style.animationDuration = (Math.random() * 3 + 5) + 's';
                flake.style.animationDelay = Math.random() * 5 + 's';
                container.appendChild(flake);
            }
        }

        function createThunder() {
            setInterval(() => {
                if (Math.random() > 0.75) {
                    const overlay = document.getElementById('weatherOverlay');
                    overlay.style.background = 'rgba(255,255,255,0.4)';
                    setTimeout(() => {
                        overlay.className = 'weather-overlay thunderstorm';
                    }, 100);
                }
            }, 3000);
        }

        function showLoading() {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('weatherInfo').style.display = 'none';
        }

        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }

        // Enter to search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') searchWeather();
        });

        // Hide suggestions on outside click
        document.addEventListener('click', function(e) {
            const searchBox = document.querySelector('.search-box');
            if (!searchBox.contains(e.target)) {
                document.getElementById('suggestions').style.display = 'none';
            }
        });

        // Init
        resize();
        initStars();
        requestAnimationFrame(frame);

        // Auto-load TPHCM
        window.addEventListener('load', function() {
            document.getElementById('searchInput').value = 'TP. Hồ Chí Minh';
            getWeatherByCoords(10.8231, 106.6297, 'TP. Hồ Chí Minh');
        });
    </script>
</body>
</html>