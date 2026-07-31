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
    const button = e.target instanceof Element ? e.target.closest("[data-profile-toggle]") : null;
    if (!button) return;
    const disclosure = button.closest("[data-profile-disclosure]");
    const detail = disclosure?.querySelector("[data-profile-edit]");
    if (!detail) return;
    const open = detail.classList.contains("is-hidden");
    detail.classList.toggle("is-hidden", !open);
    button.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) detail.querySelector("input, select, textarea")?.focus();
});
const forumMoreToggle = document.querySelector("[data-forum-more-toggle]");
const forumMoreRegion = document.getElementById("forum-more-region");
if (forumMoreToggle && forumMoreRegion) {
    forumMoreToggle.addEventListener("click", () => {
        const open = forumMoreRegion.hidden;
        forumMoreRegion.hidden = !open;
        forumMoreToggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
}
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
const _openModalBox = (title, fallback, builderFn) => new Promise(resolve => {
    if (!modal || !modalBody) { resolve(fallback); return; }
    confirmResolve = resolve;
    if (modalTitle) modalTitle.textContent = title;
    modalBody.innerHTML = "";
    const box = document.createElement("div");
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn alt";
    cancel.textContent = "取消";
    const ok = document.createElement("button");
    ok.type = "button";
    ok.className = "danger";
    const focusEl = builderFn(box, cancel, ok);
    modalBody.appendChild(box);
    modal.hidden = false;
    focusEl.focus();
    if (focusEl === ok || focusEl === cancel) return;
    if (focusEl.select) focusEl.select();
});
const openConfirm = (message, title = "确认操作") => _openModalBox(title, false, (box, cancel, ok) => {
    box.className = "confirm-box";
    const text = document.createElement("p");
    text.className = "confirm-message";
    text.textContent = message;
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.textContent = "确定";
    ok.addEventListener("click", () => finishConfirm(true));
    actions.append(cancel, ok);
    box.append(text, actions);
    return cancel;
});
const openPluginUninstallConfirm = (message, title = "卸载插件") => _openModalBox(title, false, (box, cancel, ok) => {
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
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.textContent = "卸载";
    ok.addEventListener("click", () => finishConfirm({keepData: checkbox.checked}));
    actions.append(cancel, ok);
    box.append(text, option, actions);
    return checkbox;
});
const openPrompt = (message, title = "请输入", value = "1") => _openModalBox(title, null, (box, cancel, ok) => {
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
    const done = () => { finishConfirm(String(Math.max(1, parseInt(input.value || "1", 10) || 1))); };
    cancel.addEventListener("click", () => finishConfirm(null));
    ok.textContent = "确定";
    ok.addEventListener("click", done);
    input.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); done(); } });
    actions.append(cancel, ok);
    box.append(text, input, actions);
    return input;
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
const runSettingsUpdateCheck = () => {
    const marker = document.querySelector("[data-settings-update-check-url]");
    if (!marker) return;
    fetch(marker.dataset.settingsUpdateCheckUrl || "index.php?a=update&notice_check=1", {
        credentials: "same-origin",
        headers: {"Accept": "application/json", "X-Requested-With": "XMLHttpRequest"},
    }).then(response => response.json()).then(data => {
        const title = document.querySelector("[data-update-tool-title]");
        if (!data?.update_available || !title || title.querySelector(".settings-update-dot")) return;
        const dot = document.createElement("i");
        dot.className = "settings-update-dot";
        dot.title = "发现新版本";
        dot.setAttribute("aria-label", "发现新版本");
        title.appendChild(dot);
    }).catch(() => {});
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", runSettingsUpdateCheck);
else runSettingsUpdateCheck();
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
                if (!data?.ok) throw new Error(data?.message || style + " 镜像失败");
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
        const replaceTarget = form.dataset.replaceTarget || "";
        const replaceEl = replaceTarget ? form.closest(replaceTarget) : null;
        if (replaceEl && data.html) {
            replaceEl.outerHTML = data.html;
            showToast(data.message || "已保存");
            return;
        }
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
    const floor = (quote.dataset.floor || "").trim();
    const marker = /^\d+$/.test(floor) && Number(floor) > 0 ? " #" + floor : "";
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
const attachmentFileSize = bytes => {
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1) + " MB";
    if (bytes >= 1024) return Math.round(bytes / 1024) + " KB";
    return bytes + " B";
};
const attachmentHistoryPrefix = "bbs1_attachment_upload_history_v1_";
const attachmentUploaderStates = new WeakMap();
const attachmentHistoryRead = storageKey => {
    if (!storageKey) return [];
    try {
        const value = JSON.parse(localStorage.getItem(storageKey) || "[]");
        if (!Array.isArray(value)) return [];
        const keys = new Set();
        return value.filter(entry => {
            if (!entry || typeof entry.key !== "string" || typeof entry.markdown !== "string" || !entry.key || !entry.markdown || keys.has(entry.key)) return false;
            keys.add(entry.key);
            return true;
        }).map(entry => ({
            key: entry.key,
            name: String(entry.name || "未命名附件"),
            size: Math.max(0, Number(entry.size) || 0),
            lastModified: Math.max(0, Number(entry.lastModified) || 0),
            markdown: entry.markdown,
            createdAt: Math.max(0, Number(entry.createdAt) || 0)
        }));
    } catch (_) {
        return [];
    }
};
const attachmentHistoryWrite = (storageKey, history) => {
    if (!storageKey) return;
    try {
        localStorage.setItem(storageKey, JSON.stringify(history));
    } catch (_) {}
};
const attachmentUploadItem = (list, file, key = "") => {
    const item = document.createElement("div");
    item.className = "attachment-upload-item";
    item.dataset.state = "waiting";
    if (key) item.dataset.attachmentKey = key;
    const head = document.createElement("div");
    head.className = "attachment-upload-head";
    const name = document.createElement("span");
    name.className = "attachment-upload-name";
    name.textContent = file.name || "未命名附件";
    name.title = file.name || "";
    const status = document.createElement("span");
    status.className = "attachment-upload-status";
    status.textContent = "等待上传 · " + attachmentFileSize(file.size || 0);
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "attachment-upload-remove";
    remove.dataset.attachmentRemove = "1";
    remove.setAttribute("aria-label", "不插入此附件");
    remove.title = "不插入内容";
    remove.textContent = "×";
    const progress = document.createElement("span");
    progress.className = "attachment-upload-progress";
    progress.setAttribute("role", "progressbar");
    progress.setAttribute("aria-valuemin", "0");
    progress.setAttribute("aria-valuemax", "100");
    progress.setAttribute("aria-valuenow", "0");
    const fill = document.createElement("span");
    progress.append(fill);
    head.append(name, status, remove);
    item.append(head, progress);
    list.append(item);
    return {item, status, progress, fill};
};
const updateAttachmentUploadItem = (row, state, percent, message = "") => {
    const value = Math.max(0, Math.min(100, Math.round(percent || 0)));
    row.item.dataset.state = state;
    row.fill.style.width = value + "%";
    row.progress.setAttribute("aria-valuenow", String(value));
    row.status.textContent = message || (state === "success" ? "上传完成" : state === "error" ? "上传失败" : value >= 100 ? "正在处理" : "上传 " + value + "%");
};
const attachmentUploadSuccess = (row, entry, restored = false) => {
    row.item.dataset.markdown = entry.markdown;
    row.item.dataset.attachmentKey = entry.key;
    row.item.tabIndex = 0;
    row.item.setAttribute("role", "button");
    row.item.setAttribute("aria-label", "复制 " + entry.name + " 的 Markdown");
    updateAttachmentUploadItem(row, "success", 100, restored ? "历史记录 · 点击复制" : "上传完成 · 点击复制");
};
const updateAttachmentUploadToolbar = (uploader, state) => {
    const toolbar = uploader.querySelector("[data-attachment-upload-toolbar]");
    const summary = uploader.querySelector("[data-attachment-upload-summary]");
    const button = uploader.querySelector("[data-attachment-insert-all]");
    const pending = state.history.filter(entry => !state.inserted.has(entry.key)).length;
    if (toolbar) toolbar.hidden = state.history.length === 0;
    if (summary) summary.textContent = "已上传 " + state.history.length + " 个";
    if (button) {
        button.disabled = pending === 0;
        button.textContent = pending > 0 ? "批量插入（" + pending + "）" : "已全部插入";
    }
};
const attachmentUploaderState = uploader => {
    let state = attachmentUploaderStates.get(uploader);
    if (state) return state;
    const storageKey = uploader.dataset.uploadStorageKey || "";
    const history = attachmentHistoryRead(storageKey);
    state = {storageKey, history, selected: new Set(history.map(entry => entry.key)), inserted: new Set(), dismissed: new Set()};
    attachmentUploaderStates.set(uploader, state);
    const list = uploader.querySelector("[data-attachment-upload-list]");
    if (list && history.length > 0) {
        list.hidden = false;
        history.forEach(entry => attachmentUploadSuccess(attachmentUploadItem(list, entry, entry.key), entry, true));
    }
    updateAttachmentUploadToolbar(uploader, state);
    return state;
};
const dismissAttachmentUploadItem = (uploader, key, item) => {
    if (!uploader || !key) return;
    const state = attachmentUploaderState(uploader);
    state.dismissed.add(key);
    state.history = state.history.filter(entry => entry.key !== key);
    state.inserted.delete(key);
    attachmentHistoryWrite(state.storageKey, state.history);
    item?.remove();
    const list = uploader.querySelector("[data-attachment-upload-list]");
    if (list && list.children.length === 0) list.hidden = true;
    updateAttachmentUploadToolbar(uploader, state);
};
const appendPendingAttachmentMarkdown = form => {
    const textarea = form?.querySelector("textarea[name=body]");
    if (!textarea) return 0;
    const pending = [];
    const seen = new Set();
    const uploaders = Array.from(form.querySelectorAll(".attachment-uploader[data-upload-storage-key]"));
    uploaders.forEach(uploader => {
        const state = attachmentUploaderState(uploader);
        state.history.forEach(entry => {
            if (state.inserted.has(entry.key) || seen.has(entry.key)) return;
            seen.add(entry.key);
            if (textarea.value.includes(entry.markdown)) {
                state.inserted.add(entry.key);
                return;
            }
            pending.push({entry, state});
        });
    });
    if (pending.length > 0) {
        const prefix = textarea.value === "" || textarea.value.endsWith("\n") ? "" : "\n";
        textarea.value += prefix + pending.map(item => item.entry.markdown).join("\n");
        pending.forEach(item => item.state.inserted.add(item.entry.key));
    }
    uploaders.forEach(uploader => updateAttachmentUploadToolbar(uploader, attachmentUploaderState(uploader)));
    return pending.length;
};
const clearAttachmentUploadHistory = storageKey => {
    if (!storageKey) return;
    try {
        localStorage.removeItem(storageKey);
    } catch (_) {}
    document.querySelectorAll(".attachment-uploader[data-upload-storage-key]").forEach(uploader => {
        if (uploader.dataset.uploadStorageKey !== storageKey) return;
        const state = attachmentUploaderState(uploader);
        state.history.length = 0;
        state.selected.clear();
        state.inserted.clear();
        state.dismissed.clear();
        const list = uploader.querySelector("[data-attachment-upload-list]");
        if (list) {
            list.replaceChildren();
            list.hidden = true;
        }
        updateAttachmentUploadToolbar(uploader, state);
    });
};
const consumeAttachmentUploadHistoryClear = () => {
    const marker = document.cookie.split("; ").find(item => item.startsWith("__attachment_upload_history_clear="));
    if (!marker) return false;
    const userId = marker.slice(marker.indexOf("=") + 1);
    if (/^\d+$/.test(userId)) clearAttachmentUploadHistory(attachmentHistoryPrefix + userId);
    document.cookie = "__attachment_upload_history_clear=; Max-Age=0; path=/; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
    return true;
};
const copyTextToClipboard = async text => {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const field = document.createElement("textarea");
    field.value = text;
    field.setAttribute("readonly", "");
    field.style.position = "fixed";
    field.style.opacity = "0";
    document.body.append(field);
    field.select();
    const copied = document.execCommand("copy");
    field.remove();
    if (!copied) throw new Error("复制失败");
};
const copyAttachmentMarkdown = async item => {
    const markdown = item?.dataset?.markdown || "";
    if (!markdown || item.dataset.state !== "success") return;
    const status = item.querySelector(".attachment-upload-status");
    try {
        await copyTextToClipboard(markdown);
        if (status) status.textContent = "已复制 Markdown";
        showToast("Markdown 已复制");
        item.closest("form")?.querySelector("textarea[name=body]")?.focus();
    } catch (_) {
        if (status) status.textContent = "复制失败 · 点击重试";
        showToast("复制失败");
    }
};
const uploadAttachmentFile = (url, file, onProgress) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open("POST", url, true);
    request.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    request.upload.addEventListener("progress", event => {
        if (event.lengthComputable) onProgress(event.loaded * 100 / event.total);
    });
    request.addEventListener("load", () => {
        let data;
        try {
            data = JSON.parse(request.responseText || "");
        } catch (_) {
            reject(new Error("上传失败"));
            return;
        }
        if (request.status < 200 || request.status >= 300 || !data.ok) {
            reject(new Error(data.message || "上传失败"));
            return;
        }
        resolve(data);
    });
    request.addEventListener("error", () => reject(new Error("上传失败")));
    request.addEventListener("abort", () => reject(new Error("上传已取消")));
    const body = new FormData();
    body.append("attachment", file);
    request.send(body);
});
consumeAttachmentUploadHistoryClear();
document.querySelectorAll(".attachment-uploader[data-upload-storage-key]").forEach(attachmentUploaderState);
document.addEventListener("change", async e => {
    const input = e.target.closest("[data-attachment-input]");
    if (!input) return;
    const uploader = input.closest(".attachment-uploader");
    const form = input.closest("form");
    const textarea = form?.querySelector("textarea[name=body]");
    const url = uploader?.dataset?.uploadUrl || "";
    const selectedFiles = Array.from(input.files || []);
    if (!uploader || !textarea || !url || selectedFiles.length === 0) return;
    const state = attachmentUploaderState(uploader);
    const batch = new Set();
    let duplicateCount = 0;
    const entries = [];
    selectedFiles.forEach(file => {
        const key = [file.name, file.size, file.lastModified].join("\u001f");
        if (state.selected.has(key) || batch.has(key)) {
            duplicateCount++;
            return;
        }
        batch.add(key);
        entries.push({file, key});
    });
    if (duplicateCount > 0) showToast("已忽略" + duplicateCount + "个重复文件");
    if (entries.length === 0) {
        input.value = "";
        return;
    }
    entries.forEach(entry => state.selected.add(entry.key));
    const files = entries.map(entry => entry.file);
    const maxMb = parseInt(uploader.dataset.uploadMaxMb || "20", 10) || 20;
    const list = uploader.querySelector("[data-attachment-upload-list]");
    if (list) {
        list.hidden = false;
    }
    const rows = entries.map(entry => list ? attachmentUploadItem(list, entry.file, entry.key) : null);
    input.disabled = true;
    for (const [index, file] of files.entries()) {
        const row = rows[index];
        uploader.dataset.uploadingCount = String((parseInt(uploader.dataset.uploadingCount || "0", 10) || 0) + 1);
        try {
            if (file.size > maxMb * 1024 * 1024) throw new Error("超过" + maxMb + "MB");
            if (row) updateAttachmentUploadItem(row, "uploading", 0);
            const data = await uploadAttachmentFile(url, file, percent => {
                if (row) updateAttachmentUploadItem(row, "uploading", percent);
            });
            const markdown = String(data.markdown || "");
            const entry = {key: entries[index].key, name: file.name || "未命名附件", size: file.size || 0, lastModified: file.lastModified || 0, markdown, createdAt: Date.now()};
            if (markdown && !state.dismissed.has(entry.key)) {
                state.history.push(entry);
                attachmentHistoryWrite(state.storageKey, state.history);
                if (row) attachmentUploadSuccess(row, entry);
                updateAttachmentUploadToolbar(uploader, state);
            } else if (row) updateAttachmentUploadItem(row, "success", 100, "上传完成");
        } catch (err) {
            const message = err?.message || "上传失败";
            if (row) updateAttachmentUploadItem(row, "error", 100, message);
            showToast((file.name ? file.name + "：" : "") + message);
        } finally {
            uploader.dataset.uploadingCount = String(Math.max(0, (parseInt(uploader.dataset.uploadingCount || "0", 10) || 0) - 1));
        }
    }
    input.disabled = false;
    input.value = "";
});
document.addEventListener("click", e => {
    const remove = e.target.closest("[data-attachment-remove]");
    if (remove) {
        e.preventDefault();
        e.stopPropagation();
        const item = remove.closest(".attachment-upload-item");
        dismissAttachmentUploadItem(item?.closest(".attachment-uploader"), item?.dataset.attachmentKey || "", item);
        return;
    }
    const insertAll = e.target.closest("[data-attachment-insert-all]");
    if (insertAll) {
        const uploader = insertAll.closest(".attachment-uploader");
        const textarea = uploader?.closest("form")?.querySelector("textarea[name=body]");
        if (!uploader || !textarea) return;
        const state = attachmentUploaderState(uploader);
        const entries = state.history.filter(entry => !state.inserted.has(entry.key));
        if (entries.length === 0) return;
        insertTextareaText(textarea, entries.map(entry => entry.markdown).join("\n"));
        entries.forEach(entry => state.inserted.add(entry.key));
        updateAttachmentUploadToolbar(uploader, state);
        showToast("已插入 " + entries.length + " 个附件");
        return;
    }
    const item = e.target.closest(".attachment-upload-item[data-state='success'][data-markdown]");
    if (item) copyAttachmentMarkdown(item);
});
document.addEventListener("keydown", e => {
    if (e.target.closest("[data-attachment-remove]")) return;
    const item = e.target.closest(".attachment-upload-item[data-state='success'][data-markdown]");
    if (!item || (e.key !== "Enter" && e.key !== " ")) return;
    e.preventDefault();
    copyAttachmentMarkdown(item);
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
            appendPendingAttachmentMarkdown(replyForm);
            const response = await fetch(replyForm.action, {method: "POST", body: new FormData(replyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "提交失败");
            consumeAttachmentUploadHistoryClear();
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
            if (window.turnstile && replyForm.querySelector(".cf-turnstile")) window.turnstile.reset(replyForm.querySelector(".cf-turnstile"));
            if (status) status.textContent = "已回复";
        } catch (err) {
            const message = err?.message || "提交失败";
            if (status) status.textContent = message;
            showToast(message);
            if (window.turnstile && replyForm.querySelector(".cf-turnstile")) window.turnstile.reset(replyForm.querySelector(".cf-turnstile"));
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
        appendPendingAttachmentMarkdown(form);
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
        consumeAttachmentUploadHistoryClear();
        if (data.modal && typeof data.modal === "object") {
            openModal(data.modal.title || data.message || "提示", data.modal.html || "");
            if (button) button.disabled = false;
            return;
        }
        const replaceTarget = form.dataset.replaceTarget || "";
        const replaceEl = replaceTarget ? form.closest(replaceTarget) : null;
        if (data.refresh && replaceEl) {
            try {
                const panelResponse = await fetch(window.location.href, {credentials: "same-origin"});
                const panelDoc = new DOMParser().parseFromString(await panelResponse.text(), "text/html");
                const panel = panelDoc.querySelector(replaceTarget);
                if (panel) replaceEl.outerHTML = panel.outerHTML;
            } catch (_) {}
            showToast(data.message || "操作完成");
            return;
        }
        showToast(data.message || "操作完成");
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
        if (window.turnstile && form.querySelector(".cf-turnstile")) window.turnstile.reset(form.querySelector(".cf-turnstile"));
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
    const floor = new URLSearchParams(window.location.search).get("floor") || "";
    const target = /^\d+$/.test(replyId) ? document.getElementById("post-" + replyId) : (/^\d+$/.test(floor) ? document.querySelector('[data-floor="' + floor + '"]') : null);
    if (target) target.scrollIntoView({block:"center"});
});
