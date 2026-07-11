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
const modalTitle = document.getElementById("notify-modal-title");
let confirmResolve = null;
const closeModal = () => {
    if (confirmResolve) {
        const resolve = confirmResolve;
        confirmResolve = null;
        resolve(false);
    }
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
};
const openModal = (title, html) => {
    if (!modal || !modalBody) return;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = html;
    modal.hidden = false;
};
const mobileMenu = document.getElementById("mobile-menu");
const mobileMenuOpen = document.querySelector("[data-mobile-menu-open]");
const closeMobileMenu = () => {
    if (!mobileMenu) return;
    mobileMenu.hidden = true;
    document.body.classList.remove("mobile-menu-open");
    if (mobileMenuOpen) mobileMenuOpen.setAttribute("aria-expanded", "false");
};
const openMobileMenu = () => {
    if (!mobileMenu) return;
    mobileMenu.hidden = false;
    document.body.classList.add("mobile-menu-open");
    if (mobileMenuOpen) mobileMenuOpen.setAttribute("aria-expanded", "true");
};
if (mobileMenuOpen) mobileMenuOpen.addEventListener("click", openMobileMenu);
document.addEventListener("click", e => {
    const target = e.target instanceof Element ? e.target : null;
    if (target && target.closest("[data-mobile-menu-close]")) {
        closeMobileMenu();
        return;
    }
    if (mobileMenu && !mobileMenu.hidden && target === mobileMenu) closeMobileMenu();
});
document.addEventListener("keydown", e => {
    if (e.key === "Escape") closeMobileMenu();
});
const finishConfirm = (ok) => {
    const resolve = confirmResolve;
    confirmResolve = null;
    if (modal) modal.hidden = true;
    if (modalBody) modalBody.innerHTML = "";
    if (resolve) resolve(ok);
};
const openConfirm = (message, title = "确认操作") => new Promise(resolve => {
    if (!modal || !modalBody) {
        resolve(false);
        return;
    }
    confirmResolve = resolve;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = "";
    const box = document.createElement("div");
    box.className = "confirm-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn alt";
    cancel.textContent = "取消";
    const ok = document.createElement("button");
    ok.type = "button";
    ok.className = "danger";
    ok.textContent = "确定";
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.addEventListener("click", () => finishConfirm(true));
    actions.append(cancel, ok);
    box.append(text, actions);
    modalBody.appendChild(box);
    modal.hidden = false;
    cancel.focus();
});
const openPluginUninstallConfirm = (message, title = "卸载插件") => new Promise(resolve => {
    if (!modal || !modalBody) {
        resolve(false);
        return;
    }
    confirmResolve = resolve;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = "";
    const box = document.createElement("div");
    box.className = "confirm-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const option = document.createElement("label");
    option.className = "confirm-check";
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.checked = true;
    const labelText = document.createElement("span");
    labelText.textContent = "保留插件数据";
    option.append(checkbox, labelText);
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn alt";
    cancel.textContent = "取消";
    const ok = document.createElement("button");
    ok.type = "button";
    ok.className = "danger";
    ok.textContent = "卸载";
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.addEventListener("click", () => finishConfirm({keepData: checkbox.checked}));
    actions.append(cancel, ok);
    box.append(text, option, actions);
    modalBody.appendChild(box);
    modal.hidden = false;
    checkbox.focus();
});
const openPrompt = (message, title = "请输入", value = "1") => new Promise(resolve => {
    if (!modal || !modalBody) {
        resolve(null);
        return;
    }
    confirmResolve = resolve;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = "";
    const box = document.createElement("div");
    box.className = "confirm-box prompt-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const input = document.createElement("input");
    input.className = "prompt-input";
    input.type = "number";
    input.min = "1";
    input.step = "1";
    input.value = String(value || "1");
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn alt";
    cancel.textContent = "取消";
    const ok = document.createElement("button");
    ok.type = "button";
    ok.className = "danger";
    ok.textContent = "确定";
    const done = () => {
        const n = Math.max(1, parseInt(input.value || "1", 10) || 1);
        finishConfirm(String(n));
    };
    cancel.addEventListener("click", () => finishConfirm(null));
    ok.addEventListener("click", done);
    input.addEventListener("keydown", e => {
        if (e.key === "Enter") {
            e.preventDefault();
            done();
        }
    });
    actions.append(cancel, ok);
    box.append(text, input, actions);
    modalBody.appendChild(box);
    modal.hidden = false;
    input.focus();
    input.select();
});
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
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runPageFlash);
else runPageFlash();
function avatarSeed(seed) {
    const n = String(seed || "0").replace(/\D/g, "") || "0";
    const mod = [...n].reduce((r, d) => (r * 10 + Number(d)) % 48, 0);
    return String(mod || 48);
}
function avatarPickerStyle(p) {
    const s = p?.querySelector("select[name=avatar_style]");
    return s?.value || "dylan";
}
function avatarRemoteUrl(style, seed) {
    return "https://api.dicebear.com/10.x/" + encodeURIComponent(style) + "/svg?seed=" + encodeURIComponent(seed);
}
function avatarMirrorStyles(text) {
    return String(text || "").split(/[\s,，]+/).map(s => s.trim()).filter(Boolean);
}
function avatarMirrorStylesText(styles, addStyle = "") {
    const set = new Set(avatarMirrorStyles(styles));
    if (addStyle) set.add(addStyle);
    return [...set].join(",");
}
function avatarStyleMirrored(p, style) {
    return avatarMirrorStyles(p?.dataset.avatarMirrorStyles || "").includes(style);
}
function avatarPickerUrl(p, seed) {
    const style = avatarPickerStyle(p);
    const normalizedSeed = avatarSeed(seed || p.dataset.seed || "0");
    if (p?.dataset.avatarLocalOnly === "1" && p.dataset.avatarBase) {
        return p.dataset.avatarBase + encodeURIComponent(style + "_" + normalizedSeed + ".svg");
    }
    if (p?.dataset.avatarBase && avatarStyleMirrored(p, style)) {
        return p.dataset.avatarBase + encodeURIComponent(style + "_" + normalizedSeed + ".svg");
    }
    return avatarRemoteUrl(style, normalizedSeed);
}
function setAvatarPickerImg(img, p, seed) {
    if (!img) return;
    img.onerror = null;
    img.src = avatarPickerUrl(p, seed);
}
function refreshAvatarPicker(p) {
    const k = p?.querySelector("input[name=avatar_seed]");
    const v = k?.value || "";
    const i = p?.querySelector(".avatar-picker-preview img");
    setAvatarPickerImg(i, p, v);
    p?.querySelectorAll(".avatar-option").forEach(b => {
        const seed = b.dataset.seed || "";
        const img = b.querySelector("img");
        setAvatarPickerImg(img, p, seed);
        b.classList.toggle("active", seed === v);
    });
}
function rebuildLocalAvatarPicker(p) {
    if (p?.dataset.avatarLocalOnly !== "1") return;
    const style = avatarPickerStyle(p);
    const seeds = Array.from({length: 48}, (_, i) => String(i + 1));
    const options = p.querySelector(".avatar-options");
    const hidden = p.querySelector("input[name=avatar_seed]");
    if (!options || !hidden || !seeds.length) return;
    if (!seeds.includes(hidden.value)) hidden.value = seeds[0];
    options.innerHTML = "";
    for (const seed of seeds) {
        const button = document.createElement("button");
        button.className = "avatar-option" + (seed === hidden.value ? " active" : "");
        button.type = "button";
        button.dataset.seed = seed;
        const img = document.createElement("img");
        img.className = "avatar-img";
        img.alt = "";
        img.loading = "lazy";
        img.src = avatarPickerUrl(p, seed);
        button.appendChild(img);
        options.appendChild(button);
    }
}
async function runAvatarMirror(btn) {
    const form = btn.closest("form");
    const input = form?.querySelector("[data-avatar-mirror-styles-input]");
    const status = form?.querySelector("[data-avatar-mirror-status]");
    const styles = avatarMirrorStyles(btn.dataset.styles || "");
    const seedCount = Number(btn.dataset.seedCount || 48);
    const csrf = form?.querySelector("input[name=_csrf]")?.value || "";
    if (!input || !btn.dataset.url || !styles.length) return;
    btn.disabled = true;
    try {
        const completed = new Set(avatarMirrorStyles(input.value));
        let doneStyles = completed.size;
        for (const style of styles) {
            if (completed.has(style)) continue;
            for (let i = 1; i <= seedCount; i++) {
                if (status) status.textContent = "正在镜像 " + style + "_" + i + ".svg";
                const body = new FormData();
                body.append("_csrf", csrf);
                body.append("style", style);
                body.append("seed", String(i));
                const response = await fetch(btn.dataset.url, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
                const data = await response.json();
                if (!data?.ok) throw new Error(style + " 镜像失败");
                if (status) status.textContent = style + " 已完成 " + i + " / " + seedCount;
            }
            const body = new FormData();
            body.append("_csrf", csrf);
            body.append("style", style);
            body.append("seed", "1");
            body.append("complete", "1");
            const response = await fetch(btn.dataset.url, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data?.ok) throw new Error(style + " 目录记录失败");
            input.value = data.styles || avatarMirrorStylesText(input.value, style);
            completed.add(style);
            doneStyles++;
            if (status) status.textContent = "已完成目录 " + doneStyles + " / " + styles.length;
        }
        if (status) status.textContent = "全部远程目录镜像完成";
    } catch (e) {
        if (status) status.textContent = e.message || "远程目录镜像失败";
    }
    btn.disabled = false;
}
document.addEventListener("change", e => {
    const p = e.target.closest(".avatar-picker");
    if (p) {
        if (e.target.matches("select[name=avatar_style]")) rebuildLocalAvatarPicker(p);
        refreshAvatarPicker(p);
    }
});
document.addEventListener("click", e => {
    const mirrorBtn = e.target.closest("[data-avatar-mirror-button]");
    if (mirrorBtn) {
        runAvatarMirror(mirrorBtn);
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
document.addEventListener("change", async e => {
    const input = e.target.closest("[data-auto-submit]");
    if (!input) return;
    const form = input.closest("form");
    if (!form) return;
    const previous = input.checked;
    const body = new FormData(form);
    input.disabled = true;
    try {
        const response = await fetch(form.action || window.location.href, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
        const data = await response.json();
        if (!data?.ok) throw new Error(data?.message || "保存失败");
        showToast(data.message || "已保存");
    } catch (err) {
        input.checked = !previous;
        showToast(err.message || "保存失败");
    } finally {
        input.disabled = false;
    }
});
document.addEventListener("change", e => {
    const action = e.target.closest("[data-bulk-action]");
    if (!action) return;
    toggleBulkForum(action);
});
document.addEventListener("change", e => {
    const action = e.target.closest("[data-topic-action]");
    if (!action) return;
    const form = action.closest("form");
    const highlight = form?.querySelector("[data-topic-highlight-wrap]");
    if (highlight) highlight.classList.toggle("is-hidden", action.value !== "highlight");
});
document.addEventListener("click", e => {
    const swatch = e.target.closest("[data-topic-color]");
    if (!swatch) return;
    const wrap = swatch.closest("[data-topic-highlight-wrap]");
    const form = swatch.closest("form");
    const input = form?.querySelector("[data-topic-highlight-value]");
    if (!input || !wrap) return;
    input.value = swatch.dataset.topicColor || "";
    wrap.querySelectorAll("[data-topic-color]").forEach(btn => btn.classList.toggle("active", btn === swatch));
});
window.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-bulk-action]").forEach(action => {
        toggleBulkForum(action);
    });
    document.querySelectorAll("[data-topic-action]").forEach(action => {
        action.dispatchEvent(new Event("change", {bubbles: true}));
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
document.addEventListener("click", async e => {
    const link = e.target.closest("a[data-confirm]");
    if (!link) return;
    e.preventDefault();
    e.stopPropagation();
    if (await openConfirm(link.dataset.confirm || "确定操作？")) {
        window.location.href = link.href;
    }
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
    const quoteId = (quote.dataset.id || quote.dataset.replyid || "").trim();
    const type = (quote.dataset.type || "reply").trim();
    const marker = quoteId ? (type === "topic" ? " #t" + quoteId : " #" + quoteId) : "";
    const mention = "@" + (quote.dataset.username || "").trim() + marker + " ";
    if (!textarea.value.includes(mention)) {
        const prefix = textarea.value && !textarea.value.endsWith("\n") ? "\n" : "";
        textarea.value += prefix + mention;
    }
    panel.scrollIntoView({block:"center"});
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
});
const insertTextareaText = (textarea, text) => {
    if (!textarea) return;
    const start = textarea.selectionStart ?? textarea.value.length;
    const end = textarea.selectionEnd ?? textarea.value.length;
    const before = textarea.value.slice(0, start);
    const after = textarea.value.slice(end);
    const prefix = before === "" || before.endsWith("\n") ? "" : "\n";
    const suffix = after === "" || after.startsWith("\n") ? "" : "\n";
    const insert = prefix + text + suffix;
    textarea.value = before + insert + after;
    const pos = before.length + insert.length;
    textarea.focus();
    textarea.setSelectionRange(pos, pos);
};
document.addEventListener("change", async e => {
    const input = e.target.closest("[data-attachment-input]");
    if (!input) return;
    const uploader = input.closest(".attachment-uploader");
    const form = input.closest("form");
    const textarea = form?.querySelector("textarea[name=body]");
    const list = uploader?.querySelector("[data-attachment-list]");
    const url = uploader?.dataset?.uploadUrl || "";
    const token = form?.querySelector("input[name=_csrf]")?.value || "";
    const files = Array.from(input.files || []);
    if (!uploader || !textarea || !list || !url || files.length === 0) return;
    const maxCount = parseInt(uploader.dataset.uploadMaxCount || "10", 10) || 10;
    const maxMb = parseInt(uploader.dataset.uploadMaxMb || "20", 10) || 20;
    const uploaded = parseInt(uploader.dataset.uploadedCount || "0", 10) || 0;
    if (uploaded + files.length > maxCount) {
        showToast("附件最多上传" + maxCount + "个");
        input.value = "";
        return;
    }
    input.disabled = true;
    for (const file of files) {
        const row = document.createElement("div");
        row.className = "attachment-row";
        const name = document.createElement("span");
        name.className = "attachment-name";
        name.textContent = file.name || "附件";
        const status = document.createElement("span");
        status.className = "attachment-status";
        status.textContent = "上传中";
        row.append(name, status);
        list.appendChild(row);
        uploader.dataset.uploadingCount = String((parseInt(uploader.dataset.uploadingCount || "0", 10) || 0) + 1);
        if (file.size > maxMb * 1024 * 1024) {
            row.classList.add("error");
            status.textContent = "超过" + maxMb + "MB";
            uploader.dataset.uploadingCount = String(Math.max(0, (parseInt(uploader.dataset.uploadingCount || "0", 10) || 0) - 1));
            continue;
        }
        try {
            const body = new FormData();
            body.append("_csrf", token);
            body.append("attachment", file);
            const response = await fetch(url, {method: "POST", body, headers: {"X-Requested-With": "XMLHttpRequest"}});
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (_) {
                throw new Error("上传失败");
            }
            if (!data.ok) throw new Error(data.message || "上传失败");
            insertTextareaText(textarea, data.markdown || "");
            row.classList.add("done");
            status.textContent = "已插入";
            uploader.dataset.uploadedCount = String((parseInt(uploader.dataset.uploadedCount || "0", 10) || 0) + 1);
        } catch (err) {
            row.classList.add("error");
            status.textContent = err?.message || "上传失败";
            showToast(status.textContent);
        } finally {
            uploader.dataset.uploadingCount = String(Math.max(0, (parseInt(uploader.dataset.uploadingCount || "0", 10) || 0) - 1));
        }
    }
    input.disabled = false;
    input.value = "";
});
document.addEventListener("submit", async e => {
    if (e.defaultPrevented) return;
    const uploading = e.target?.querySelector?.(".attachment-uploader[data-uploading-count]:not([data-uploading-count='0'])");
    if (uploading) {
        e.preventDefault();
        showToast("附件上传中");
        return;
    }
    const promptField = e.submitter?.dataset?.promptField || e.target?.dataset?.promptField || "";
    if (promptField) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        const input = e.target.elements?.[promptField];
        const value = await openPrompt(e.submitter?.dataset?.promptMessage || e.target?.dataset?.promptMessage || "请输入", e.submitter?.dataset?.promptTitle || e.target?.dataset?.promptTitle || "请输入", e.submitter?.dataset?.promptValue || e.target?.dataset?.promptValue || input?.value || "1");
        if (value === null || value === false) return;
        if (input) input.value = value;
        e.target.submit();
        return;
    }
    if (e.target?.dataset?.pluginUninstall === "1") {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        const result = await openPluginUninstallConfirm(e.target.dataset.confirm || "确定卸载插件？");
        if (!result) return;
        let input = e.target.elements?.keep_plugin_data;
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.name = "keep_plugin_data";
            e.target.appendChild(input);
        }
        input.value = result.keepData ? "1" : "0";
    } else {
        const confirmMessage = e.submitter?.dataset?.confirm || e.target?.dataset?.confirm || "";
        if (confirmMessage) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            if (!await openConfirm(confirmMessage)) return;
        }
    }
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
            if (status) status.textContent = "已回复";
        } catch (err) {
            const message = err?.message || "提交失败";
            if (status) status.textContent = message;
            showToast(message);
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
        if (data.modal && typeof data.modal === "object") {
            openModal(data.modal.title || data.message || "提示", data.modal.html || "");
            if (button) button.disabled = false;
            return;
        }
        showToast(data.message || "操作完成");
        const replaceTarget = form.dataset.replaceTarget || "";
        const replaceEl = replaceTarget ? form.closest(replaceTarget) : null;
        if (replaceEl && data.html) {
            replaceEl.outerHTML = data.html;
            return;
        }
        const removeTarget = form.dataset.removeTarget || "";
        const removeEl = removeTarget ? form.closest(removeTarget) : null;
        if (removeEl) {
            removeEl.remove();
            return;
        }
        if (data.redirect) setTimeout(() => { window.location.href = data.redirect; }, 800);
    } catch (err) {
        showToast(err?.message || "操作失败");
        if (button) button.disabled = false;
    }
});
window.addEventListener("load", () => {
    const shareForm = document.querySelector("form[data-plugin-share-auto='1']");
    if (shareForm) {
        shareForm.submit();
        return;
    }
    const replyId = new URLSearchParams(window.location.search).get("replyid") || "";
    if (!/^\d+$/.test(replyId)) return;
    const target = document.getElementById("post-" + replyId);
    if (target) target.scrollIntoView({block:"center"});
});
