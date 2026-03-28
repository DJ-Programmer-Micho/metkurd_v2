
const siteConfig = {
  brand: 'KurdishAI',
  nav: [
    ['index.html','Home'],['tools.html','Tools'],['pricing.html','Pricing'],['contact.html','Contact']
  ]
};

function currentFile(){
  const path = window.location.pathname.split('/').pop();
  return path || 'index.html';
}

function navbar(){
  const file = currentFile();
  return `
  <a class="skip-link" href="#main-content">Skip to content</a>
  <nav class="navbar navbar-expand-lg site-navbar fixed-top">
    <div class="container py-2">
      <a class="navbar-brand d-flex align-items-center gap-2 text-white" href="index.html" aria-label="KurdishAI Home">
        <span class="brand-badge"><i class="bi bi-stars"></i></span>
        <span>${siteConfig.brand}</span>
      </a>
      <button class="navbar-toggler btn btn-outline-soft" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
      </button>
      <div class="collapse navbar-collapse" id="siteNav">
        <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
          ${siteConfig.nav.map(([href,label]) => `<li class="nav-item"><a class="nav-link ${file===href?'active':''}" href="${href}">${label}</a></li>`).join('')}
        </ul>
        <div class="d-flex align-items-center gap-2">
          <button class="mode-toggle" id="themeToggle" aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
          <a href="signin.html" class="btn btn-link text-decoration-none text-white-50 d-none d-lg-inline-flex">Sign in</a>
          <a href="signup.html" class="btn btn-glow rounded-pill px-4">Try it for Free</a>
        </div>
      </div>
    </div>
  </nav>`;
}

function footer(){
  return `
  <footer class="site-footer">
    <div class="container">
      <div class="newsletter glass-card mb-5 reveal">
        <div class="row align-items-center g-4">
          <div class="col-lg-7">
            <span class="section-badge mb-3"><i class="bi bi-envelope-paper"></i> Join the Kurdish AI newsletter</span>
            <h3 class="fw-bold mb-2">Stay updated on new CKB AI releases, demos, and research tools.</h3>
            <p class="text-muted-soft mb-0">Get product updates, launch news, and early access opportunities.</p>
          </div>
          <div class="col-lg-5">
            <form class="row g-2" onsubmit="event.preventDefault(); alert('Newsletter demo only. Connect your backend later.');">
              <div class="col-8"><input class="form-control" type="email" placeholder="Enter your email" aria-label="Email address"></div>
              <div class="col-4 d-grid"><button class="btn btn-glow">Subscribe</button></div>
            </form>
          </div>
        </div>
      </div>
      <div class="row g-4">
        <div class="col-lg-4">
          <div class="d-flex align-items-center gap-2 mb-3"><span class="brand-badge"><i class="bi bi-stars"></i></span><strong>${siteConfig.brand}</strong></div>
          <p class="text-muted-soft">AI tools purpose-built for Kurdish Sorani: speech, OCR, voice cloning, and language workflows in one premium platform.</p>
          <div class="d-flex gap-2">
            <a class="social-link" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
            <a class="social-link" href="#" aria-label="X"><i class="bi bi-twitter-x"></i></a>
            <a class="social-link" href="#" aria-label="GitHub"><i class="bi bi-github"></i></a>
            <a class="social-link" href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
          </div>
        </div>
        <div class="col-6 col-lg-2">
          <h6 class="fw-bold mb-3">Platform</h6>
          <div class="d-flex flex-column gap-2">
            <a class="footer-link" href="tools.html">Tools</a>
            <a class="footer-link" href="pricing.html">Pricing</a>
            <a class="footer-link" href="signup.html">Sign up</a>
            <a class="footer-link" href="signin.html">Sign in</a>
          </div>
        </div>
        <div class="col-6 col-lg-2">
          <h6 class="fw-bold mb-3">Resources</h6>
          <div class="d-flex flex-column gap-2">
            <a class="footer-link" href="tts.html">TTS-CKB</a>
            <a class="footer-link" href="asr.html">ASR-CKB</a>
            <a class="footer-link" href="ocr.html">OCR-CKB</a>
          </div>
        </div>
        <div class="col-6 col-lg-2">
          <h6 class="fw-bold mb-3">Legal</h6>
          <div class="d-flex flex-column gap-2">
            <a class="footer-link" href="terms.html">Terms</a>
            <a class="footer-link" href="privacy.html">Privacy</a>
            <a class="footer-link" href="contact.html">Contact</a>
          </div>
        </div>
        <div class="col-6 col-lg-2">
          <h6 class="fw-bold mb-3">Status</h6>
          <div class="d-flex flex-column gap-2 text-muted-soft">
            <span>99.95% uptime</span>
            <span>Cloud secured</span>
            <span>WCAG-friendly UI</span>
          </div>
        </div>
      </div>
      <hr class="my-4" style="border-color: var(--stroke);">
      <div class="d-flex flex-column flex-md-row justify-content-between gap-2 text-muted-soft small">
        <span>© 2026 KurdishAI. All rights reserved.</span>
        <span>Built for Kurdish (CKB – Sorani) innovation.</span>
      </div>
    </div>
  </footer>`;
}

function initTheme(){
  const saved = localStorage.getItem('theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
  updateThemeIcon(saved);
  document.addEventListener('click', e => {
    const btn = e.target.closest('#themeToggle');
    if(!btn) return;
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateThemeIcon(next);
  });
}
function updateThemeIcon(theme){
  const icon = document.querySelector('#themeToggle i');
  if(icon) icon.className = theme === 'dark' ? 'bi bi-moon-stars' : 'bi bi-sun';
}

function initReveal(){
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if(entry.isIntersecting) entry.target.classList.add('in-view');
    });
  }, {threshold: .14});
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
}

function initPricingToggle(){
  const toggle = document.getElementById('billingToggle');
  if(!toggle) return;
  const update = () => {
    const yearly = toggle.checked;
    document.querySelectorAll('[data-monthly][data-yearly]').forEach(el => {
      el.textContent = yearly ? el.dataset.yearly : el.dataset.monthly;
    });
    document.querySelectorAll('[data-period]').forEach(el => {
      el.textContent = yearly ? '/year' : '/month';
    });
  };
  toggle.addEventListener('change', update);
  update();
}

document.addEventListener('DOMContentLoaded', () => {
  const header = document.getElementById('site-header');
  const foot = document.getElementById('site-footer');
  if(header) header.innerHTML = navbar();
  if(foot) foot.innerHTML = footer();
  initTheme();
  initReveal();
  initPricingToggle();
});
