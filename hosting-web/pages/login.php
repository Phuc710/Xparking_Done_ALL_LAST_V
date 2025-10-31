<?php
// pages/login.php - Login with starry sky background
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - XPARKING</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
<style>
        html,body{height:100%;margin:0;overflow:hidden}
        body{font-family:system-ui,'Segoe UI',Roboto,sans-serif}

        #sky{
            position:fixed;inset:0;width:100%;height:100%;display:block;z-index:0;
            background:linear-gradient(180deg,#050a14 0%, #070d1d 50%, #030710 100%);
        }

        .ui {
            position:relative;z-index:10;display:flex;align-items:center;justify-content:center;height:100%;padding:2rem;
        }

        .login-card {
            background:rgba(255,255,255,0.06);
            backdrop-filter:blur(12px);
            padding:3rem 2.5rem;
            border-radius:24px;
            color:#cfe8ff;
            border:1px solid rgba(255,255,255,0.1);
            box-shadow:0 8px 32px rgba(2,6,23,0.8);
            max-width:420px;
            width:100%;
        }

        .logo-container {
            text-align:center;
            margin-bottom:2rem;
        }

        .logo-container img {
            height:60px;
            margin-bottom:1rem;
        }

        .logo-container h2 {
            font-size:2rem;
            font-weight:700;
            margin-bottom:0.5rem;
            color:#fff;
            text-shadow:0 2px 8px rgba(0,0,0,0.3);
        }

        .logo-container p {
            color:rgba(207,232,255,0.6);
            font-size:0.95rem;
        }

        .form-group {
            margin-bottom:1.5rem;
        }

        .form-label {
            color:#cfe8ff;
            margin-bottom:0.5rem;
            font-weight:500;
        }

        .form-control {
            background:rgba(255,255,255,0.08);
            border:1px solid rgba(255,255,255,0.2);
            color:#fff;
            padding:0.8rem 1rem;
            border-radius:12px;
            transition:all 0.3s;
        }

        .form-control:focus {
            background:rgba(255,255,255,0.12);
            border-color:rgba(102,126,234,0.6);
            box-shadow:0 0 0 3px rgba(102,126,234,0.2);
            color:#fff;
            outline:none;
        }

        .form-control::placeholder {
            color:rgba(207,232,255,0.4);
        }

        .btn-login {
            width:100%;
            padding:0.9rem;
            background:rgba(102,126,234,0.9);
            border:none;
            border-radius:12px;
            color:#fff;
            font-weight:600;
            font-size:1.05rem;
            cursor:pointer;
            transition:all 0.3s;
        }

        .btn-login:hover {
            background:rgba(102,126,234,1);
            transform:translateY(-2px);
            box-shadow:0 6px 20px rgba(102,126,234,0.4);
        }

        .divider {
            text-align:center;
            margin:1.5rem 0;
            color:rgba(207,232,255,0.5);
        }

        .register-link {
            text-align:center;
            margin-top:1.5rem;
        }

        .register-link a {
            color:rgba(102,126,234,0.9);
            text-decoration:none;
            font-weight:600;
            transition:color 0.3s;
        }

        .register-link a:hover {
            color:rgba(102,126,234,1);
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

        @media (max-width:576px) {
            .login-card{padding:2rem 1.5rem;}
    }
</style>
</head>
<body>
    <canvas id="sky"></canvas>
    
    <button class="back-btn" onclick="window.location.href='../index.php'">
        <i class="fas fa-arrow-left me-2"></i>Quay lại
    </button>
    
    <div class="ui">
        <div class="login-card">
            <div class="logo-container">
                <img src="../LOGO.gif" alt="XPARKING">
                <h2>XPARKING</h2>
                <p>Đăng nhập vào hệ thống</p>
            </div>
            
            <form action="../index.php?action=login" method="POST">
                <div class="form-group">
                    <label class="form-label">Tên đăng nhập</label>
                    <input type="text" name="username" class="form-control" 
                           placeholder="Nhập username hoặc email" required>
            </div>
            
            <div class="form-group">
                    <label class="form-label">Mật khẩu</label>
                    <input type="password" name="password" class="form-control" 
                           placeholder="Nhập mật khẩu" required>
            </div>
            
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Đăng nhập
                </button>
        </form>
        
            <div class="divider">hoặc</div>
            
            <div class="register-link">
                Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a>
            </div>
        </div>
    </div>
    
    <script>
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

        window.addEventListener('resize', ()=>{resize();initStars();});

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

        resize();
        initStars();
        requestAnimationFrame(frame);
    </script>
</body>
</html>