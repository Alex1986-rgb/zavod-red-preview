new Swiper('.swiper-news', {
    loop: true,
    slidesPerView: 3,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.news-next',   // ← твой класс
        prevEl: '.news-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 1, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 3, spaceBetween: 20 }
    }
});

new Swiper('.swiper-reviews', {
    loop: true,
    slidesPerView: 2,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.reviews-next',   // ← твой класс
        prevEl: '.reviews-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 1, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 3, spaceBetween: 20 }
    }
});

new Swiper('.swiper-applications', {
    loop: true,
    slidesPerView: 3,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.applications-next',   // ← твой класс
        prevEl: '.applications-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 1, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 3, spaceBetween: 20 }
    }
});

new Swiper('.swiper-team', {
    loop: true,
    slidesPerView: 4,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.team-next',   // ← твой класс
        prevEl: '.team-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 1, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 4, spaceBetween: 20 }
    }
});

new Swiper('.swiper-certificates', {
    loop: true,
    slidesPerView: 3,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.certificates-next',   // ← твой класс
        prevEl: '.certificates-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 2, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 3, spaceBetween: 20 }
    }
});

new Swiper('.swiper-photo-proizvodstvo', {
    loop: true,
    slidesPerView: 5,
    spaceBetween: 20,

    // Указываем свои классы для стрелок
    navigation: {
        nextEl: '.photo-proizvodstvo-next',   // ← твой класс
        prevEl: '.photo-proizvodstvo-prev',   // ← твой класс
    },

    breakpoints: {
        320: { slidesPerView: 1, spaceBetween: 10 },
        768: { slidesPerView: 2, spaceBetween: 20 },
        1024: { slidesPerView: 3, spaceBetween: 20 }
    }
});


document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('.catalog-list');
    const viewIcons = document.querySelectorAll('.catalog-view i');

    // --- Настройки ---
    const modes = [
        { icon: 'fa-align-justify', class: 'view-list' },
        { icon: 'fa-list', class: 'view-grid-2' },
        { icon: 'fa-th-large', class: 'view-grid-4' }
    ];

    let swipers = []; // храним экземпляры Swiper

    // --- Уничтожение всех Swiper ---
    function destroySwipers() {
        swipers.forEach(s => s.destroy && s.destroy(true, true));
        swipers = [];
    }

    // --- Инициализация Swiper + hover-навигация ---
    function initSwipers() {
        destroySwipers();

        document.querySelectorAll('.swiper-catalog').forEach(slider => {

function createPagination(slider) {
    const pagination = document.createElement('div');
    pagination.classList.add('swiper-pagination');
    slider.appendChild(pagination);
    return pagination;
}            
            // Инициализируем Swiper
            const swiper = new Swiper(slider, {
                loop: true,
                slidesPerView: 1,
                grabCursor: true,
                speed: 600, // плавная анимация
                effect: 'slide', // можно заменить на 'fade' для затухания
                observer: true,
                observeParents: true,

                navigation: {
                    nextEl: slider.querySelector('.catalog-next'),
                    prevEl: slider.querySelector('.catalog-prev'),
                },
                pagination: {
                    el: slider.querySelector('.swiper-pagination') || createPagination(slider),
                    clickable: true,
                    type: 'bullets'
                }                
            });

            swipers.push(swiper);

            // --- Листание по наведению (лево/право) ---
            let intervalId = null;

            function getSide(element, event) {
                const rect = element.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                return event.clientX < centerX ? 'left' : 'right';
            }

            function startSlide(direction) {
                stopSlide();
                intervalId = setInterval(() => {
                    if (direction === 'left') {
                        swiper.slidePrev();
                    } else {
                        swiper.slideNext();
                    }
                }, 600); // скорость перелистывания
            }

            function stopSlide() {
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                }
            }

            slider.addEventListener('mouseenter', (e) => {
                startSlide(getSide(slider, e));
            });

            slider.addEventListener('mousemove', (e) => {
                const side = getSide(slider, e);
                if (intervalId) {
                    stopSlide();
                    startSlide(side);
                }
            });

            slider.addEventListener('mouseleave', () => {
                stopSlide();
            });
        });
    }

    // --- Подсветка активной иконки ---
    function updateActiveIcon(iconClass) {
        viewIcons.forEach(icon => {
            if (icon.classList.contains(iconClass)) {
                icon.style.color = '#ff5f15';
                icon.style.transform = 'scale(1.1)';
            } else {
                icon.style.color = '';
                icon.style.transform = '';
            }
        });
    }

    // --- Обработка клика по иконкам ---
    viewIcons.forEach(icon => {
        icon.addEventListener('click', () => {
            const clickedIcon = [...icon.classList].find(cls =>
                ['fa-align-justify', 'fa-list', 'fa-th-large'].includes(cls)
            );

            const mode = modes.find(m => m.icon === clickedIcon);
            if (!mode) return;

            // Смена класса
            modes.forEach(m => container.classList.remove(m.class));
            container.classList.add(mode.class);

            updateActiveIcon(clickedIcon);
            localStorage.setItem('catalogView', mode.class);

            // Перезапуск Swiper с задержкой (чтобы CSS применился)
            setTimeout(initSwipers, 100);
        });
    });

    // --- Восстановление вида ---
    const savedView = localStorage.getItem('catalogView');
    const savedMode = modes.find(m => m.class === savedView);
    if (savedMode) {
        container.classList.add(savedMode.class);
        updateActiveIcon(savedMode.icon);
    } else {
        container.classList.add('view-grid-2');
        updateActiveIcon('fa-list');
    }

    // --- Инициализация Swiper после всех изменений ---
    setTimeout(initSwipers, 100);

    // Опционально: если вы подгружаете товары динамически
    // — вызывайте initSwipers() после добавления новых
});




document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('.swiper-main')) {
        const swiperMain = new Swiper('.swiper-main', {
            loop: true,
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
            effect: 'fade',
            fadeEffect: {
                crossFade: true
            },
            // Подключаем thumbs
            thumbs: {
                swiper: {
                    el: '.swiper-thumbs',
                    slidesPerView: 4,
                    spaceBetween: 10,
                    freeMode: true,
                    watchSlidesProgress: true,
                }
            }
        });

        // Дополнительно: клик по превью
        const thumbsSwiper = swiperMain.thumbs.swiper;
        thumbsSwiper.on('click', function () {
            swiperMain.slideTo(this.clickedIndex);
        });
    }
});