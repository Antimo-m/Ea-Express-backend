const page = document.querySelector('[data-not-found]');

if (page) {
    const counter = page.querySelector('[data-not-found-countdown]');
    let timer;
    const stop = () => window.clearInterval(timer);
    const start = () => {
        stop();
        let seconds = 5;
        counter.textContent = String(seconds);
        timer = window.setInterval(() => {
            seconds -= 1;
            counter.textContent = String(seconds);
            if (seconds === 0) {
                stop();
                window.location.replace(page.dataset.homeUrl);
            }
        }, 1000);
    };
    start();
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', event => { if (event.persisted) start(); });
}
