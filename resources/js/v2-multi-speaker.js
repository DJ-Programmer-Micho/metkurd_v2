export function mountSegments(ctx) {
    let dragged = null;
    ctx.listen(ctx.root, 'dragstart', event => {
        if (!event.target.closest('[data-segment-handle]')) return;
        dragged = event.target.closest('[data-segment-id]')?.dataset.segmentId;
        if (dragged) event.dataTransfer?.setData('text/plain', dragged);
    });
    ctx.listen(ctx.root, 'dragover', event => {
        if (dragged && event.target.closest('[data-segment-id]')) event.preventDefault();
    });
    ctx.listen(ctx.root, 'drop', event => {
        const target = event.target.closest('[data-segment-id]')?.dataset.segmentId;
        if (!dragged || !target || dragged === target) return;
        event.preventDefault();
        const ids = Array.from(ctx.root.querySelectorAll('[data-segment-id]'), row => row.dataset.segmentId);
        const from = ids.indexOf(dragged), to = ids.indexOf(target);
        if (from >= 0 && to >= 0) {
            ids.splice(from, 1); ids.splice(to, 0, dragged);
            ctx.component()?.call('reorderSegments', ids);
        }
        dragged = null;
    });
    ctx.listen(ctx.root, 'dragend', () => { dragged = null; });
    return { destroy() { dragged = null; } };
}

if (typeof window !== 'undefined') {
    (window.MetKurdV2Pages ||= []).push({key: 'multi-speaker-sort', selector: '.v2-multi-speaker-page', boot: mountSegments});
}
