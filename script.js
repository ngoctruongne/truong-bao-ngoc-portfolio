// ===== ROOT SCOPE =====
const R = document.getElementById('ngoc-portfolio');
if (!R) console.warn('#ngoc-portfolio wrapper not found');

// ===== DARK / LIGHT THEME TOGGLE =====
const themeToggleBtn = document.getElementById('theme-toggle');
const savedTheme = localStorage.getItem('tbn-theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

function applyTheme(theme) {
  const isDark = theme === 'dark';
  if (R) {
    if (isDark) R.classList.add('dark-theme');
    else R.classList.remove('dark-theme');
  }
  if (isDark) {
    document.body.classList.add('dark-theme');
    document.documentElement.classList.add('dark-theme');
  } else {
    document.body.classList.remove('dark-theme');
    document.documentElement.classList.remove('dark-theme');
  }
  if (themeToggleBtn) {
    themeToggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-sun" style="color: #f58220;"></i>' : '<i class="fa-solid fa-moon"></i>';
    themeToggleBtn.setAttribute('title', isDark ? 'Chuyển sang chế độ Sáng' : 'Chuyển sang chế độ Tối');
  }
}

applyTheme(savedTheme);

if (themeToggleBtn) {
  themeToggleBtn.addEventListener('click', () => {
    const isDark = R && R.classList.contains('dark-theme');
    const newTheme = isDark ? 'light' : 'dark';
    localStorage.setItem('tbn-theme', newTheme);
    applyTheme(newTheme);
  });
}

// ===== NAVBAR =====
const navbar = R && R.querySelector('.navbar');
window.addEventListener('scroll', () => {
  if (navbar) navbar.classList.toggle('scrolled', window.scrollY > 50);
  updateActiveNav();
});

const navToggle = R && R.querySelector('#nav-toggle');
const navMenu = R && R.querySelector('#nav-menu');
if (navToggle && navMenu) {
  navToggle.addEventListener('click', () => navMenu.classList.toggle('open'));
  navMenu.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => navMenu.classList.remove('open')));
}

function updateActiveNav() {
  if (!R) return;
  const sections = R.querySelectorAll('section[id]');
  const links = R.querySelectorAll('.nav-link');
  let current = '';
  sections.forEach(s => { if (window.scrollY >= s.offsetTop - 120) current = s.id; });
  links.forEach(l => {
    l.classList.remove('active');
    if (l.getAttribute('href') === '#' + current) l.classList.add('active');
  });
}

// ===== COUNTER ANIMATION =====
let countersRan = false;
function animateCounters() {
  if (countersRan || !R) return;
  countersRan = true;
  R.querySelectorAll('.stat-num').forEach(el => {
    const target = +el.dataset.target;
    const dur = 1800, step = target / (dur / 16);
    let current = 0;
    const timer = setInterval(() => {
      current += step;
      if (current >= target) { el.textContent = target; clearInterval(timer); }
      else el.textContent = Math.floor(current);
    }, 16);
  });
}

// ===== SKILL BARS =====
let skillsRan = false;
function animateSkillBars() {
  if (skillsRan || !R) return;
  skillsRan = true;
  R.querySelectorAll('.skill-bar-fill').forEach(bar => { bar.style.width = bar.dataset.width + '%'; });
}

// ===== INTERSECTION OBSERVERS =====
if (R) {
  const heroSection = R.querySelector('#home');
  const aboutSection = R.querySelector('#about');

  const secObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        if (entry.target === heroSection) animateCounters();
        if (entry.target === aboutSection) animateSkillBars();
      }
    });
  }, { threshold: 0.15 });

  if (heroSection) secObserver.observe(heroSection);
  if (aboutSection) secObserver.observe(aboutSection);

  // Card animations
  const cardObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
      if (entry.isIntersecting) {
        setTimeout(() => entry.target.classList.add('visible'), 80 * (+(entry.target.dataset.delay || 0)));
        cardObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });

  R.querySelectorAll('.expertise-card, .portfolio-card, .timeline-item, .highlight-item, .contact-item').forEach((el, i) => {
    el.classList.add('anim-card');
    el.dataset.delay = i % 3;
    cardObserver.observe(el);
  });
}

// Trigger counters if hero already visible
if (window.scrollY < 100) animateCounters();

// ===== PORTFOLIO FILTER =====
if (R) {
  const filterBtns = R.querySelectorAll('.filter-btn');
  const portfolioCards = R.querySelectorAll('.portfolio-card');
  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      filterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const filter = btn.dataset.filter;
      portfolioCards.forEach(card => {
        if (filter === 'all' || card.dataset.category === filter) {
          card.classList.remove('hidden');
        } else {
          card.classList.add('hidden');
        }
      });
    });
  });

  // ===== BLOG FILTER =====
  const bfilterBtns = R.querySelectorAll('.bfilter-btn');
  const blogCards = R.querySelectorAll('.blog-card');
  bfilterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      bfilterBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const filter = btn.dataset.bfilter;
      blogCards.forEach(card => {
        const cat = card.dataset.category || '';
        if (filter === 'all' || cat.includes(filter)) {
          card.style.display = card.classList.contains('featured-blog') ? (window.innerWidth > 900 ? 'grid' : 'flex') : 'flex';
          card.classList.remove('hidden');
        } else {
          card.style.display = 'none';
          card.classList.add('hidden');
        }
      });
    });
  });
}

// ===== LIGHTBOX =====
let currentIndex = 0;
const images = [];

if (R) {
  R.querySelectorAll('.portfolio-card').forEach(card => {
    const img = card.querySelector('img');
    images.push(img ? img.src : null);
  });
}

function openLightbox(index) {
  currentIndex = index;
  const lightbox = document.getElementById('lightbox');
  const content = document.getElementById('lightbox-content');
  if (!lightbox || !content) return;
  const img = images[index];
  if (img) {
    content.innerHTML = `<img src="${img}" alt="Portfolio ${index + 1}" />`;
  } else {
    content.innerHTML = `<div style="color:#5d6184;padding:60px 40px;text-align:center;font-size:1.05rem;background:#ffffff;border-radius:20px;border:1px solid rgba(21,23,51,0.08);"><i class='fa-solid fa-image' style='font-size:3rem;display:block;margin-bottom:16px;color:#8e92b0'></i>Chưa có hình ảnh — Thêm ảnh vào thư mục assets/images/</div>`;
  }
  lightbox.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeLightbox() {
  const lightbox = document.getElementById('lightbox');
  if (lightbox) lightbox.classList.remove('open');
  document.body.style.overflow = '';
}

const lbClose = document.getElementById('lightbox-close');
const lbPrev = document.getElementById('lightbox-prev');
const lbNext = document.getElementById('lightbox-next');
const lbBox = document.getElementById('lightbox');

if (lbClose) lbClose.addEventListener('click', closeLightbox);
if (lbBox) lbBox.addEventListener('click', e => { if (e.target === e.currentTarget) closeLightbox(); });
if (lbPrev) lbPrev.addEventListener('click', () => { currentIndex = (currentIndex - 1 + images.length) % images.length; openLightbox(currentIndex); });
if (lbNext) lbNext.addEventListener('click', () => { currentIndex = (currentIndex + 1) % images.length; openLightbox(currentIndex); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeLightbox();
  if (e.key === 'ArrowLeft' && lbPrev) lbPrev.click();
  if (e.key === 'ArrowRight' && lbNext) lbNext.click();
});

// ===== CONTACT FORM =====
const contactForm = document.getElementById('contact-form');
if (contactForm) {
  contactForm.addEventListener('submit', e => {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Đã gửi thành công!';
    btn.style.background = 'linear-gradient(135deg,#16a57e,#10b981)';
    setTimeout(() => { btn.innerHTML = original; btn.style.background = ''; }, 3000);
    e.target.reset();
  });
}
