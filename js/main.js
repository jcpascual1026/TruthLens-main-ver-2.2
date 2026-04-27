document.addEventListener('DOMContentLoaded', () => {
    const navToggle = document.querySelector('.nav-toggle');
    const mainNav = document.querySelector('.main-nav');
    const header = document.querySelector('.site-header');
    const themeToggle = document.getElementById('theme-toggle');
    const html = document.documentElement;
    const fade = document.getElementById('theme-fade');

    if (!navToggle || !mainNav) return;

    let overlay = document.querySelector('.nav-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'nav-overlay';
        document.body.appendChild(overlay);
    }

    const openNav = () => {
        mainNav.classList.add('open');
        overlay.classList.add('active');
        navToggle.classList.add('active');
        navToggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('nav-open');
    };

    const closeNav = () => {
        mainNav.classList.remove('open');
        overlay.classList.remove('active');
        navToggle.classList.remove('active');
        navToggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('nav-open');
    };

    const toggleNav = () => {
        mainNav.classList.contains('open') ? closeNav() : openNav();
    };

    navToggle.addEventListener('click', toggleNav);
    overlay.addEventListener('click', closeNav);

    mainNav.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) closeNav();
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeNav();
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) closeNav();
    });

    window.addEventListener('scroll', () => {
        header?.classList.toggle('scrolled', window.scrollY > 10);
    });

    const savedTheme = localStorage.getItem('theme') || 'light';

    const applyTheme = (theme) => {
        html.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        themeToggle?.setAttribute('aria-pressed', theme === 'dark');
    };

    applyTheme(savedTheme);

    const runThemeFade = () => {
        if (!fade) return;
        fade.classList.add('active');
        setTimeout(() => fade.classList.remove('active'), 700);
    };

    themeToggle?.addEventListener('click', () => {
        const isDark = html.getAttribute('data-theme') === 'dark';
        const newTheme = isDark ? 'light' : 'dark';

        runThemeFade();

        requestAnimationFrame(() => {
            setTimeout(() => {
                applyTheme(newTheme);
            }, 180);
        });
    });

    const animateElements = document.querySelectorAll(
        '.feature-item, .step-card, .team-card, .about-card, .hero-copy, .hero-visual'
    );

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                    obs.unobserve(entry.target);
                }
            });
        }, {
            rootMargin: '0px 0px -60px 0px',
            threshold: 0.1
        });

        animateElements.forEach((el, i) => {
            el.style.transitionDelay = `${i * 70}ms`;
            observer.observe(el);
        });
    }
});