const toastEl = () => document.getElementById("toast");
const showToast = (message) => {
    const toast = toastEl();
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.hidden = true, 1800);
};
const refreshCaptchaIn = (root) => {
    root?.querySelectorAll(".captcha-img").forEach(img => {
        const url = new URL(img.getAttribute("src") || "", window.location.href);
        url.searchParams.set("r", Date.now().toString(36) + Math.random().toString(36).slice(2, 6));
        img.src = url.pathname + url.search;
    });
    root?.querySelectorAll('input[name="captcha"]').forEach(input => input.value = "");
};
const modal = document.getElementById("notify-modal");
const modalBody = document.getElementById("notify-modal-body");
const modalTitle = document.getElementById("notify-modal-title");
const closeModal = () => {
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
};
const openModal = (title, html) => {
    if (!modal || !modalBody) return;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = html;
    modal.hidden = false;
};
window.openNotify = async function (url) {
    try {
        const response = await fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}});
        const html = await response.text();
        if ((response.headers.get("content-type") || "").includes("application/json")) {
            const data = JSON.parse(html);
            if (data.redirect) window.location.href = data.redirect;
            else showToast(data.message || "打开失败");
            return false;
        }
        openModal("私信TA", html);
        const textarea = modalBody?.querySelector("form")?.querySelector("textarea");
        textarea?.focus();
        textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
    } catch (_) {
        showToast("打开失败");
    }
    return false;
};
const runPageFlash = () => {
    if (window.__pageFlash) showToast(window.__pageFlash);
};
const runAutoHome = () => {
    const panel = document.querySelector("[data-auto-home]");
    if (!panel) return;
    const target = panel.dataset.autoHome || "/";
    const output = panel.querySelector("[data-auto-home-countdown]");
    const message = panel.querySelector("[data-auto-home-message]");
    const messageText = message?.textContent || "";
    const messageMatch = messageText.match(/请\s*(\d+)\s*秒后再试/);
    let seconds = Math.max(1, parseInt(panel.dataset.autoHomeSeconds || "5", 10) || 5);
    let messageSeconds = messageMatch ? Math.max(0, parseInt(messageMatch[1], 10) || 0) : null;
    const render = () => {
        if (output) output.textContent = String(Math.max(0, seconds));
        if (message && Number.isInteger(messageSeconds)) {
            message.textContent = messageText.replace(/请\s*\d+\s*秒后再试/, "请 " + Math.max(0, messageSeconds) + " 秒后再试");
        }
    };
    render();
    const timer = setInterval(() => {
        seconds -= 1;
        if (Number.isInteger(messageSeconds)) messageSeconds -= 1;
        render();
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = target;
        }
    }, 1000);
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runPageFlash);
else runPageFlash();
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runAutoHome);
else runAutoHome();
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
    const captchaButton = e.target.closest("[data-captcha-refresh]");
    if (captchaButton) {
        refreshCaptchaIn(captchaButton.closest("form") || document);
        return;
    }
    const b = e.target.closest(".avatar-option");
    if (!b) return;
    const p = b.closest(".avatar-picker");
    const k = p?.querySelector("input[name=avatar_seed]");
    if (k) {
        k.value = b.dataset.seed || "";
        refreshAvatarPicker(p);
    }
});
document.addEventListener("change", e => {
    const all = e.target.closest("[data-select-all]");
    if (!all) return;
    const form = all.closest("form");
    const root = form || document;
    root.querySelectorAll('input[type="checkbox"][name="ids[]"]').forEach(box => {
        box.checked = all.checked;
    });
});
document.addEventListener("change", e => {
    const action = e.target.closest("[data-bulk-action]");
    if (!action) return;
    toggleBulkForum(action);
});
window.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-bulk-action]").forEach(action => {
        toggleBulkForum(action);
    });
});
window.toggleBulkForum = function (action) {
    const wrap = action?.closest(".bulk-action-group")?.querySelector("[data-bulk-forum-wrap]");
    if (!wrap) return;
    const show = action.value === "move";
    wrap.classList.toggle("is-hidden", !show);
};
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
    const replyid = (quote.dataset.replyid || "").trim();
    const type = (quote.dataset.type || "reply").trim();
    const mention = "@" + (quote.dataset.username || "").trim() + (replyid ? " #" + type + replyid : "") + " ";
    if (!textarea.value.includes(mention)) {
        const prefix = textarea.value && !textarea.value.endsWith("\n") ? "\n" : "";
        textarea.value += prefix + mention;
    }
    panel.scrollIntoView({block:"center"});
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
});
document.addEventListener("click", e => {
    if (!e.target.closest("[data-command-help]")) return;
    openModal("管理指令帮助", '<div class="command-help-pop"><p>@作者 #replyID 删除</p><p>@作者 #topicID 删除</p><p>@作者 #topicID 转移 新版块名</p><p>@作者 #topicID 置顶</p><p>@作者 #topicID 取消置顶</p><p>@作者 #topicID 高亮 color:#d94b4b;font-weight:700</p><p>@作者 #topicID 取消高亮</p><p>@作者 #replyID 禁止发言</p><p>@作者 #replyID 取消禁止发言</p><p>@作者 #replyID 禁止访问</p><p>@作者 #replyID 取消禁止访问</p></div>');
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
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            list?.querySelector(".empty-state")?.remove();
            if (data.html) list?.insertAdjacentHTML("beforeend", data.html);
            const title = document.querySelector(".post-topic-title");
            const stats = title?.querySelector(".post-content-stats");
            if (title) {
                if (data.stats_html) {
                    if (stats) stats.outerHTML = data.stats_html;
                    else title.insertAdjacentHTML("beforeend", data.stats_html);
                } else if (stats) stats.remove();
            }
            replyForm.reset();
            refreshCaptchaIn(replyForm);
            if (status) status.textContent = "已回复";
        } catch (err) {
            const message = err?.message || "提交失败";
            if (status) status.textContent = message;
            showToast(message);
            refreshCaptchaIn(replyForm);
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
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
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
    if (form.dataset.noAjax === "1") return;
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
        showToast(data.message || "操作完成");
        refreshCaptchaIn(form);
        if (data.redirect) setTimeout(() => { window.location.href = data.redirect; }, 800);
    } catch (err) {
        showToast(err?.message || "操作失败");
        refreshCaptchaIn(form);
        if (button) button.disabled = false;
    }
});
window.addEventListener("load", () => {
    const match = window.location.hash.match(/^#post-(\d+)$/);
    if (!match) return;
    const target = document.getElementById("post-" + match[1]);
    if (target) target.scrollIntoView({block:"center"});
});
