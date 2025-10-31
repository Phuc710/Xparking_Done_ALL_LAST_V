// scripts/index.js - Landing page JavaScript

// Smooth scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// Navbar scroll effect
window.addEventListener('scroll', function() {
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    }
});

// Animation on scroll
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -100px 0px'
};

const observer = new IntersectionObserver(function(entries) {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('fade-in');
        }
    });
}, observerOptions);

document.querySelectorAll('.animate-on-scroll').forEach(element => {
    observer.observe(element);
});

// Real-time slot status
function updateSlotStatus() {
    fetch('api/slots_status.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const emptySlots = data.data.filter(slot => slot.status === 'empty').length;
                const totalSlots = data.data.length;
                
                // Update display
                const slotDisplay = document.getElementById('available-slots');
                if (slotDisplay) {
                    slotDisplay.textContent = `${emptySlots}/${totalSlots} slot trống`;
                }
            }
        })
        .catch(error => {
            console.error('Error fetching slots:', error);
        });
}

// Update every 10 seconds
setInterval(updateSlotStatus, 10000);
updateSlotStatus();

// Contact form handler
const contactForm = document.getElementById('contact-form');
if (contactForm) {
    contactForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('pages/send_mail.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Cảm ơn bạn! Chúng tôi sẽ liên hệ sớm.');
                contactForm.reset();
            } else {
                alert('Có lỗi xảy ra. Vui lòng thử lại.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Có lỗi xảy ra. Vui lòng thử lại.');
        });
    });
}

// Login/Register form toggle
function toggleAuthForms() {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    
    if (loginForm && registerForm) {
        loginForm.classList.toggle('d-none');
        registerForm.classList.toggle('d-none');
    }
}

// Loading spinner
function showLoading() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
        spinner.classList.remove('d-none');
    }
}

function hideLoading() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
        spinner.classList.add('d-none');
    }
}

