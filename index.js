const toastEl = () => document.getElementById("toast");
const showToast = (message) => {
    const toast = toastEl();
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.hidden = true, 1800);
};
const modal = document.getElementById("notify-modal");
const modalBody = document.getElementById("notify-modal-body");
const closeModal = () => {
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
};
window.openNotify = async function (url) {
    try {
        const response = await fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}});
        const html = await response.text();
        if (modal && modalBody) {
            modalBody.innerHTML = html;
            modal.hidden = false;
            modalBody.querySelector("form")?.querySelector("textarea")?.focus();
        }
    } catch (_) {
        showToast("打开失败");
    }
    return false;
};
const runPageFlash = () => {
    if (window.__pageFlash) showToast(window.__pageFlash);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runPageFlash);
else runPageFlash();
function avatarPickerUrl(p, seed) {
    const s = p?.querySelector("select[name=avatar_style]");
    return "https://api.dicebear.com/10.x/" + encodeURIComponent(s?.value || "dylan") + "/svg?seed=" + encodeURIComponent(seed || p.dataset.seed || "0");
}
function refreshAvatarPicker(p) {
    const k = p?.querySelector("input[name=avatar_seed]");
    const v = k?.value || "";
    const i = p?.querySelector(".avatar-picker-preview img");
    if (i) i.src = avatarPickerUrl(p, v);
    p?.querySelectorAll(".avatar-option").forEach(b => {
        const seed = b.dataset.seed || "";
        const img = b.querySelector("img");
        if (img) img.src = avatarPickerUrl(p, seed);
        b.classList.toggle("active", seed === v);
    });
}
document.addEventListener("change", e => {
    const p = e.target.closest(".avatar-picker");
    if (p) refreshAvatarPicker(p);
});
document.addEventListener("click", e => {
    const b = e.target.closest(".avatar-option");
    if (!b) return;
    const p = b.closest(".avatar-picker");
    const k = p?.querySelector("input[name=avatar_seed]");
    if (k) {
        k.value = b.dataset.seed || "";
        refreshAvatarPicker(p);
    }
});
document.addEventListener("click", e => {
    if (e.target?.closest("[data-modal-close]") || e.target === modal) closeModal();
});
document.addEventListener("click", e => {
    const quote = e.target.closest(".quote-reply");
    if (!quote) return;
    e.preventDefault();
    const textarea = document.querySelector("#reply textarea[name=body]");
    const panel = document.getElementById("reply");
    if (!textarea || !panel) {
        window.location.href = quote.href;
        return;
    }
    const mention = "@" + (quote.dataset.username || "").trim() + " ";
    if (!textarea.value.includes(mention)) textarea.value = mention + textarea.value;
    panel.scrollIntoView({block:"center"});
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
});
document.addEventListener("submit", async e => {
    const replyForm = e.target.closest(".ajax-reply-form");
    if (replyForm) {
        e.preventDefault();
        const button = replyForm.querySelector("button");
        const status = replyForm.querySelector(".reply-status");
        const list = document.querySelector(".topic-post-list");
        button.disabled = true;
        if (status) status.textContent = "提交中";
        try {
            const response = await fetch(replyForm.action, {method: "POST", body: new FormData(replyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "提交失败");
            list?.querySelector(".empty-state")?.remove();
            list?.insertAdjacentHTML("beforeend", data.html);
            const title = document.querySelector(".post-topic-title");
            const stats = title?.querySelector(".post-content-stats");
            if (title) {
                if (data.stats_html) {
                    if (stats) stats.outerHTML = data.stats_html;
                    else title.insertAdjacentHTML("beforeend", data.stats_html);
                } else if (stats) stats.remove();
            }
            replyForm.reset();
            if (status) status.textContent = "已回复";
        } catch (_) {
            if (status) status.textContent = "提交失败";
        } finally {
            button.disabled = false;
        }
        return;
    }
    const notifyForm = e.target.closest(".notify-form");
    if (notifyForm) {
        e.preventDefault();
        const button = notifyForm.querySelector("button");
        const status = notifyForm.querySelector(".notify-status");
        button.disabled = true;
        if (status) status.textContent = "发送中";
        try {
            const response = await fetch(notifyForm.action, {method: "POST", body: new FormData(notifyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "发送失败");
            closeModal();
            showToast(data.message || "已发送");
        } catch (err) {
            showToast(err?.message || "发送失败");
        } finally {
            button.disabled = false;
            if (status) status.textContent = "";
        }
        return;
    }
    const form = e.target.closest("form");
    if (!form || (form.method || "").toLowerCase() !== "post") return;
    e.preventDefault();
    const button = e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]");
    if (button) button.disabled = true;
    try {
        const body = new FormData(form);
        if (button?.name) body.append(button.name, button.value ?? "1");
        const response = await fetch(form.action || window.location.href, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (_) {
            throw new Error("操作失败");
        }
        if (!data.ok) throw new Error(data.message || "操作失败");
        if (data.redirect) window.location.href = data.redirect;
        else showToast(data.message || "操作成功");
    } catch (err) {
        showToast(err?.message || "操作失败");
        if (button) button.disabled = false;
    }
});
window.addEventListener("load", () => {
    const match = window.location.hash.match(/^#post-(\d+)$/);
    if (!match) return;
    const target = document.getElementById("post-" + match[1]);
    if (target) target.scrollIntoView({block:"center"});
});
