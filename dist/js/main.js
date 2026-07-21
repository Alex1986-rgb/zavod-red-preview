// ===================================================================
// === 1. ГЛОБАЛЬНАЯ КОНФИГУРАЦИЯ И КОНСТАНТЫ
// ===================================================================
const App = {
    config: {
        itemsPerPage: 20,
        offsetTop: 150,
        cookieConsentDays: 365,
        cookieDeclineDays: 1,
        debounceDelay: 400,
        modalShowDelay: 5000
    },
    state: {
        currentPage: 1,
        itemsPerPage: 20
    }
};

// Утилита: проверка мобильного устройства
function isMobile() {
    return window.matchMedia("(max-width: 768px)").matches;
}

// Утилита: безопасный доступ к DOM
function $(selector) {
    return document.querySelector(selector);
}

function $$(selector) {
    return document.querySelectorAll(selector);
}

// ===================================================================
// === 2. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (UTILS)
// ===================================================================
const Utils = {
    setCookie(name, value, days) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = `${name}=${value}; expires=${date.toUTCString()}; path=/`;
        try {
            // Фолбэк для локальной разработки (file://), где куки не работают
            localStorage.setItem(name, value);
        } catch (e) { }
    },

    getCookie(name) {
        try {
            const localVal = localStorage.getItem(name);
            if (localVal) return localVal;
        } catch (e) { }

        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            const c = cookies[i].trim();
            if (c.startsWith(name + '=')) {
                return c.substring(name.length + 1);
            }
        }
        return null;
    },

    scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    scrollToElement(element, offset = 0) {
        if (element) {
            const top = element.offsetTop - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        }
    }
};

// ===================================================================
// === 3. UI: БУРГЕР, СТИКИ ХЕДЕР, НАВЕРХ
// ===================================================================
const UI = {
    init() {
        this.initStickyHeader();
        this.initBackToTop();
        this.initBurgerMenu();
        this.initMobileSubmenu();
    },

    initStickyHeader() {
        if (window.matchMedia("(min-width: 1025px)").matches) {
            const header = $('header');
            if (!header) return;

            const updateSticky = () => header.classList.toggle('sticky', window.scrollY > 100);
            window.addEventListener('scroll', updateSticky);
            setTimeout(updateSticky, 100);
        }
    },

    initBackToTop() {
        const btn = $('#backToTop');
        if (!btn) return;

        window.addEventListener('scroll', () => btn.classList.toggle('show', window.scrollY > 100));
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            Utils.scrollToTop();
        });
    },

    initBurgerMenu() {
        const burger = $('#burger');
        const sideMenu = $('#sideMenu');
        const mainContent = $('main');
        if (!burger || !sideMenu) return;

        burger.addEventListener('click', () => {
            burger.classList.toggle('active');
            sideMenu.classList.toggle('open');
            if (mainContent) mainContent.classList.toggle('open');
        });
    },

    initMobileSubmenu() {
        $$('.nav-mobile > li.menu-item-has-children').forEach(item => {
            const submenu = item.querySelector('.submenu');
            const dropdownToggle = item.querySelector('.dropdown-toggle');
            if (!submenu || !dropdownToggle) return;

            dropdownToggle.addEventListener('click', function (e) {
                e.preventDefault();
                submenu.classList.toggle('open');
                this.querySelector('.arrow').classList.toggle('open');
            });
        });
    }
};

// ===================================================================
// === 4. COOKIE CONSENT MODAL
// ===================================================================
const CookieConsent = {
    init() {
        const modal = $('#cookieConsentBlock #cookieModal');
        const acceptBtn = $('#cookieConsentBlock #acceptCookies');
        const declineBtn = $('#cookieConsentBlock #declineCookies');

        if (!modal || !acceptBtn || !declineBtn) return;

        const consent = Utils.getCookie('cookiesConsent');
        if (consent === 'accepted' || consent === 'declined') {
            modal.style.display = 'none';
        } else {
            setTimeout(() => modal.classList.add('show'), App.config.modalShowDelay);
        }

        acceptBtn.addEventListener('click', () => {
            Utils.setCookie('cookiesConsent', 'accepted', App.config.cookieConsentDays);
            this.hide(modal);
        });

        declineBtn.addEventListener('click', () => {
            Utils.setCookie('cookiesConsent', 'declined', App.config.cookieDeclineDays);
            this.hide(modal);
        });
    },

    hide(modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.style.display = 'none', 500);
    }
};

// ===================================================================
// === 5. ГЛОССАРИЙ: ПОДСВЕТКА АКТИВНОГО РАЗДЕЛА
// ===================================================================
const Glossary = {
    menuLinks: $$('.glossary-menu a'),
    categoryBlocks: $$('.glossary-category-block'),
    OFFSET_TOP: App.config.offsetTop,

    init() {
        if (this.menuLinks.length === 0 || this.categoryBlocks.length === 0) return;

        this.menuLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const targetId = link.getAttribute('href');
                const targetElement = document.querySelector(targetId);
                if (targetElement) Utils.scrollToElement(targetElement, this.OFFSET_TOP);
            });
        });

        window.addEventListener('scroll', () => this.updateActiveLink());
        window.addEventListener('load', () => this.updateActiveLink());
    },

    getActiveSection() {
        let activeBlock = null;
        let minDistance = Infinity;
        const viewportTop = this.OFFSET_TOP;

        this.categoryBlocks.forEach(block => {
            const rect = block.getBoundingClientRect();
            const blockTop = rect.top;
            const blockBottom = rect.bottom;

            if (blockBottom > viewportTop && blockTop < window.innerHeight) {
                const distance = Math.abs(blockTop - viewportTop);
                if (distance < minDistance) {
                    minDistance = distance;
                    activeBlock = block;
                }
            }
        });

        return activeBlock;
    },

    updateActiveLink() {
        const activeBlock = this.getActiveSection();
        this.menuLinks.forEach(link => {
            link.classList.remove('active');
            const targetId = link.getAttribute('href');
            if (activeBlock && targetId === '#' + activeBlock.id) {
                link.classList.add('active');
            }
        });
    }
};

// ===================================================================
// === 10. ФУТЕР: АККОРДЕОН МЕНЮ
// ===================================================================
const FooterMenu = {
    init() {
        $$('.footer-menu__head-toggle').forEach(button => {
            button.addEventListener('click', () => {
                const list = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                $$('.footer-menu__list').forEach(l => l.classList.remove('active'));
                $$('.footer-menu__head-toggle').forEach(b => b.setAttribute('aria-expanded', 'false'));

                if (list) list.classList.toggle('active', !isExpanded);
                button.setAttribute('aria-expanded', !isExpanded);
            });
        });
    }
};

// ===================================================================
// === 11. ИНИЦИАЛИЗАЦИЯ ПРИЛОЖЕНИЯ
// ===================================================================
document.addEventListener('DOMContentLoaded', () => {
    UI.init();
    CookieConsent.init();
    Glossary.init();
    FooterMenu.init();
});

// FAQ Accordion
document.querySelectorAll('.faq-list_question').forEach(question => {
    question.addEventListener('click', () => {
        const item = question.parentElement;
        const icon = question.querySelector('.faq-icon');

        item.classList.toggle('active');

        document.querySelectorAll('.faq-list__item').forEach(other => {
            if (other !== item) {
                other.classList.remove('active');
            }
        });
    });
});

// Tabs
document.addEventListener('DOMContentLoaded', function () {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    tabButtons.forEach(button => {
        button.addEventListener('click', function () {
            const tabName = this.getAttribute('data-tab');

            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            this.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        });
    });
});

// Catalog View Toggle — без сохранения в localStorage
document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('.catalog-list');
    const viewIcons = document.querySelectorAll('.catalog-view i');

    const modes = [
        { icon: 'fa-align-justify', class: 'view-list', title: 'Список' },
        { icon: 'fa-list', class: 'view-grid-2', title: 'Сетка 2' },
    ];

    // Установим режим по умолчанию
    container.classList.add('view-grid-2');
    updateActiveIcon('fa-list');

    viewIcons.forEach(icon => {
        icon.addEventListener('click', function () {
            const clickedIconClass = this.classList.contains('fa-align-justify')
                ? 'fa-align-justify'
                : this.classList.contains('fa-list')
                    ? 'fa-list'
                    : 'fa-th-large';

            const mode = modes.find(m => m.icon === clickedIconClass);
            if (!mode) return;

            modes.forEach(m => container.classList.remove(m.class));
            container.classList.add(mode.class);

            updateActiveIcon(clickedIconClass);
        });
    });

    function updateActiveIcon(activeIconClass) {
        viewIcons.forEach(icon => {
            if (icon.classList.contains(activeIconClass)) {
                icon.style.color = '#ff5f15';
            } else {
                icon.style.color = '';
                icon.style.transform = '';
            }
        });
    }
});

// Язык: переключатель Google Translate
document.addEventListener("DOMContentLoaded", function () {
    const selected = document.getElementById("lang-selected");
    const optionsContainer = document.getElementById("lang-options");

    const langs = [
        { code: 'ru', flag: 'https://flagcdn.com/ru.svg', label: 'Русский' },
        { code: 'kk', flag: 'https://flagcdn.com/kz.svg', label: 'Казахский' },
        { code: 'en', flag: 'https://flagcdn.com/us.svg', label: 'Английский' },
        { code: 'zh-CN', flag: 'https://flagcdn.com/cn.svg', label: 'Китайский' }
    ];

    langs.forEach(lang => {
        const option = document.createElement("div");
        option.className = "lang-option";

        const img = document.createElement("img");
        img.src = lang.flag;
        img.alt = lang.label;
        img.loading = "lazy";

        option.appendChild(img);
        option.dataset.lang = lang.code;

        option.addEventListener("click", function () {
            const currentImg = selected.querySelector(".lang-flag");
            currentImg.src = lang.flag;
            currentImg.alt = lang.label;

            optionsContainer.classList.remove("show");

            const select = document.querySelector('select.goog-te-combo');
            if (select) {
                select.value = lang.code;
                select.dispatchEvent(new Event('change'));
            } else {
                console.warn('Google Translate ещё не загружен.');
            }
        });

        optionsContainer.appendChild(option);
    });

    selected.addEventListener("click", function () {
        optionsContainer.classList.toggle("show");
    });

    document.addEventListener("click", function (e) {
        if (!selected.contains(e.target) && !optionsContainer.contains(e.target)) {
            optionsContainer.classList.remove("show");
        }
    });
});

// About Adv Animation
document.addEventListener("DOMContentLoaded", function () {
    const items = document.querySelectorAll('.about-adv .item');

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        }
    );

    items.forEach(item => {
        observer.observe(item);
    });
});

// Social Toggle
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('socialToggle');
    const popup = document.getElementById('socialPopup');

    if (toggle && popup) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            popup.classList.toggle('active');
        });

        document.addEventListener('click', function (e) {
            if (!popup.contains(e.target)) {
                popup.classList.remove('active');
            }
        });
    }
});

// Phone Mask
document.addEventListener('DOMContentLoaded', function () {
    function initPhoneMask(selector) {
        const phoneInputs = document.querySelectorAll(selector);

        phoneInputs.forEach(input => {
            if (input.IMask) return;

            IMask(input, {
                mask: '+7 (000) 000-00-00',
            });
        });
    }

    initPhoneMask('input[type="tel"]');

    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind('[data-fancybox]', {
            on: {
                reveal: (fancybox, slide) => {
                    const href = slide.$trigger.getAttribute('href');
                    if (href && href.startsWith('#')) {
                        const targetId = href.substring(1);
                        if (targetId) {
                            initPhoneMask('#' + targetId + ' input[type="tel"]');
                        }
                    }
                }
            }
        });
    }
});

// Vacancy Form
document.addEventListener("DOMContentLoaded", function () {
    const buttons = document.querySelectorAll('.btn[data-fancybox][data-vacancy-name]');

    buttons.forEach(button => {
        button.addEventListener('click', function () {
            const vacancyName = this.getAttribute('data-vacancy-name');
            const modal = document.querySelector('#vacancysend');
            if (modal) {
                const hiddenField = modal.querySelector('#vacancy-name');
                if (hiddenField) {
                    hiddenField.value = vacancyName;
                }
            }
        });
    });
});