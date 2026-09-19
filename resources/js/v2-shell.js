(window.MetKurdV2Pages ||= []).push({key: 'shell', selector: '.v2-topbar', boot(ctx) {
    const instances = new Map();
    const update = () => {
        if (!window.bootstrap?.Dropdown) return;
        ctx.root.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(button => {
            if (!instances.has(button)) instances.set(button, window.bootstrap.Dropdown.getOrCreateInstance(button));
        });
    };
    if (window.Waves && !window.__v2WavesInitialized) {
        window.__v2WavesInitialized = true;
        window.Waves.init();
        window.Waves.attach('.waves-effect');
    }
    window.metkurdV2SetLocale = locale => {
        const input = document.getElementById('v2-selected-locale'), form = document.getElementById('v2-language-form');
        if (input && form) { input.value = locale; form.submit(); }
    };
    update();
    return {update, destroy() { instances.forEach(instance => { instance.hide(); instance.dispose(); }); instances.clear(); }};
}});
