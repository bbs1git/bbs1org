const toastEl = () => document.getElementById("toast");
const showToast = (message) => {
    const toast = toastEl();
    if (!toast) return;
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => toast.hidden = true, 1800);
};
const filterLocalPlugins = e => {
    const input = e.target instanceof HTMLInputElement && e.target.matches("[data-plugin-local-search]") ? e.target : null;
    if (!input) return;
    const panel = input.closest(".plugin-list-panel");
    const list = panel?.querySelector("[data-plugin-local-list]");
    if (!list) return;
    const query = input.value.trim().toLocaleLowerCase();
    let matches = 0;
    list.querySelectorAll("[data-plugin-local-search-text]").forEach(item => {
        const matched = query === "" || (item.dataset.pluginLocalSearchText || "").toLocaleLowerCase().includes(query);
        item.hidden = !matched;
        item.classList.toggle("is-hidden", !matched);
        if (matched) matches++;
    });
    const empty = list.querySelector("[data-plugin-local-empty]");
    if (empty) {
        empty.hidden = matches > 0;
        empty.classList.toggle("is-hidden", matches > 0);
    }
};
document.addEventListener("input", filterLocalPlugins);
document.addEventListener("search", filterLocalPlugins);
const markButtonPending = button => {
    if (!button) return;
    button.classList.add("is-click-pending");
    button.setAttribute("aria-busy", "true");
};
window.addEventListener("pageshow", () => {
    document.querySelectorAll(".is-click-pending").forEach(button => {
        button.classList.remove("is-click-pending");
        button.removeAttribute("aria-busy");
    });
});
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
const openMobileMenu = async () => {
    if (!mobileMenu) return;
    mobileMenu.hidden = false;
    document.body.classList.add("mobile-menu-open");
    if (mobileMenuOpen) mobileMenuOpen.setAttribute("aria-expanded", "true");
    const body = mobileMenu.querySelector(".mobile-menu-body");
    if (!body || body.dataset.loaded) return;
    body.dataset.loaded = "loading";
    body.textContent = "加载中…";
    try {
        const response = await fetch(mobileMenu.dataset.mobileMenuUrl, {headers: {"X-Requested-With": "XMLHttpRequest"}});
        if (!response.ok) throw new Error();
        body.innerHTML = await response.text();
        body.dataset.loaded = "1";
    } catch {
        delete body.dataset.loaded;
        body.textContent = "加载失败，请重新打开菜单";
    }
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
document.addEventListener("click", e => {
    const button = e.target.closest("[data-plugin-upload-open]");
    if (!button) return;
    const template = document.querySelector("[data-plugin-upload-template]");
    if (!template) return;
    openModal("插件上传", template.innerHTML);
    modalBody?.querySelector("[data-plugin-upload-file]")?.focus();
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
const openPluginMarketInstallConfirm = (message, action = "安装") => _openModalBox(action + "插件", false, (box, cancel, ok) => {
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
    labelText.textContent = "自动启用插件";
    option.append(checkbox, labelText);
    const actions = document.createElement("div");
    actions.className = "confirm-actions";
    cancel.addEventListener("click", () => finishConfirm(false));
    ok.textContent = action;
    ok.addEventListener("click", () => finishConfirm({autoEnable: checkbox.checked}));
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
const initTabBarWrap = () => {
    const bars = Array.from(document.querySelectorAll(".tab-bar"));
    if (!bars.length) return;
    const refresh = bar => {
        const tabs = Array.from(bar.querySelectorAll(":scope > .tab"));
        tabs.forEach(tab => tab.classList.remove("tab-wrapped", "tab-line-start", "tab-line-end"));
        const first = tabs[0];
        if (!first) return;
        const firstTop = first.offsetTop;
        tabs.forEach((tab, index) => {
            const wrapped = tab.offsetTop > firstTop + 1;
            const previous = tabs[index - 1];
            const next = tabs[index + 1];
            tab.classList.toggle("tab-wrapped", wrapped);
            tab.classList.toggle("tab-line-start", Boolean(previous && tab.offsetTop > previous.offsetTop + 1));
            tab.classList.toggle("tab-line-end", !next || next.offsetTop > tab.offsetTop + 1);
        });
    };
    let queued = false;
    const schedule = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            bars.forEach(refresh);
        });
    };
    schedule();
    window.addEventListener("resize", schedule);
    window.addEventListener("load", schedule, { once: true });
    document.fonts?.ready?.then(schedule);
    if ("ResizeObserver" in window) {
        const observer = new ResizeObserver(schedule);
        bars.forEach(bar => observer.observe(bar));
    }
};
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initTabBarWrap);
else initTabBarWrap();
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
function avatarPickerUrl(p, seed) {
    const style = avatarPickerStyle(p);
    const normalizedSeed = avatarSeed(seed || p.dataset.seed || "0");
    if (p?.dataset.avatarLocalOnly === "1" && p.dataset.avatarBase) {
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
document.addEventListener("change", e => {
    const p = e.target.closest(".avatar-picker");
    if (p) {
        if (e.target.matches("select[name=avatar_style]")) rebuildLocalAvatarPicker(p);
        refreshAvatarPicker(p);
    }
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
document.addEventListener("change", async e => {
    const input = e.target.closest("[data-auto-submit]");
    if (!input) return;
    const form = input.closest("form");
    if (!form) return;
    const previous = input.checked;
    const body = new FormData(form);
    input.disabled = true;
    try {
        const response = await fetch(form.action || window.location.href, {method: "POST", body, credentials: "same-origin", headers: {"X-Requested-With": "XMLHttpRequest"}});
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
    const action = e.target.closest("[data-topic-action]");
    if (!action) return;
    const form = action.closest("form");
    form?.querySelectorAll("[data-topic-action-secondary]").forEach(field => {
        field.classList.toggle("is-hidden", field.dataset.topicActionSecondary !== action.value);
    });
});
function syncTopicExtensionFields(toggle) {
    const panel = toggle.closest("[data-topic-extension]");
    const fields = panel?.querySelector("[data-topic-extension-fields]");
    if (fields) fields.disabled = !toggle.checked;
}
document.addEventListener("change", e => {
    const toggle = e.target instanceof Element ? e.target.closest("[data-topic-extension-toggle]") : null;
    if (toggle instanceof HTMLInputElement) syncTopicExtensionFields(toggle);
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
    document.querySelectorAll("[data-topic-action]").forEach(action => {
        action.dispatchEvent(new Event("change", {bubbles: true}));
    });
    document.querySelectorAll("input[data-topic-extension-toggle]").forEach(syncTopicExtensionFields);
});
document.addEventListener("click", e => {
    if (e.target?.closest("[data-modal-close]")) closeModal();
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
document.addEventListener("submit", async e => {
    if (e.defaultPrevented) return;
    if (window.bbs1AttachmentUpload?.isUploading(e.target)) {
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
        markButtonPending(e.submitter || e.target.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
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
    } else if (e.target?.dataset?.pluginMarketInstall === "1") {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        const result = await openPluginMarketInstallConfirm(e.target.dataset.confirm || "确定安装插件？", e.target.dataset.pluginMarketAction || "安装");
        if (!result) return;
        let input = e.target.elements?.auto_enable;
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.name = "auto_enable";
            e.target.appendChild(input);
        }
        input.value = result.autoEnable ? "1" : "0";
    } else {
        const confirmMessage = e.submitter?.dataset?.confirm || e.target?.dataset?.confirm || "";
        if (confirmMessage) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            if (!await openConfirm(confirmMessage)) return;
            if (e.target?.dataset?.noAjax === "1") {
                markButtonPending(e.submitter || e.target.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
                e.target.submit();
                return;
            }
        }
    }
    const replyForm = e.target.closest(".ajax-reply-form");
    if (replyForm) {
        e.preventDefault();
        if (replyForm.dataset.submitting === "1") return;
        replyForm.dataset.submitting = "1";
        const button = e.submitter?.form === replyForm ? e.submitter : replyForm.querySelector("button[type=submit],button:not([type]),input[type=submit]");
        const status = replyForm.querySelector(".reply-status");
        const list = document.querySelector(".topic-post-list");
        const loadingText = button?.dataset?.loadingText || "";
        const buttonText = button?.textContent || "";
        if (button) {
            button.disabled = true;
            button.setAttribute("aria-busy", "true");
            if (loadingText) {
                button.textContent = loadingText;
            }
        }
        if (status) status.textContent = "提交中";
        try {
            window.bbs1AttachmentUpload?.beforeSubmit(replyForm);
            const response = await fetch(replyForm.action, {method: "POST", body: new FormData(replyForm), headers: {"X-Requested-With": "XMLHttpRequest"}});
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || "提交失败");
            window.bbs1AttachmentUpload?.afterSubmit();
            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }
            let reply = null;
            list?.querySelector(".empty-state")?.remove();
            if (data.html && list) {
                list.insertAdjacentHTML("beforeend", data.html);
                reply = list.lastElementChild?.matches(".post-entry") ? list.lastElementChild : null;
            }
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
            if (data.tip) showToast(data.tip);
            document.dispatchEvent(new CustomEvent("bbs1:reply-saved", {detail: {form: replyForm, reply}}));
        } catch (err) {
            const message = err?.message || "提交失败";
            if (status) status.textContent = message;
            showToast(message);
            if (window.turnstile && replyForm.querySelector(".cf-turnstile")) window.turnstile.reset(replyForm.querySelector(".cf-turnstile"));
        } finally {
            delete replyForm.dataset.submitting;
            if (button) {
                button.disabled = false;
                button.removeAttribute("aria-busy");
                if (loadingText) {
                    button.textContent = buttonText;
                }
            }
        }
        return;
    }
    const notifyForm = e.target.closest(".notify-form");
    if (notifyForm) {
        e.preventDefault();
        const button = notifyForm.querySelector("button");
        const status = notifyForm.querySelector(".notify-status");
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
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
            showToast(data.tip || data.message || "已发送");
        } catch (err) {
            showToast(err?.message || "发送失败");
        } finally {
            button.disabled = false;
            button.removeAttribute("aria-busy");
            if (status) status.textContent = "";
        }
        return;
    }
    const form = e.target.closest("form");
    if (!form) return;
    const modalForm = form.closest("#notify-modal");
    if (form.dataset.noAjax === "1") {
        markButtonPending(e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]"));
        return;
    }
    if ((form.method || "").toLowerCase() !== "post") return;
    e.preventDefault();
    const button = e.submitter || form.querySelector("button[type=submit],button:not([type]),input[type=submit]");
    const loadingText = button?.dataset?.loadingText || "";
    const buttonText = button?.textContent || "";
    const resetButton = () => {
        if (!button) return;
        button.disabled = false;
        button.removeAttribute("aria-busy");
        if (loadingText) {
            button.textContent = buttonText;
        }
    };
    if (button) {
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
        if (loadingText) {
            button.textContent = loadingText;
        }
    }
    try {
        window.bbs1AttachmentUpload?.beforeSubmit(form);
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
        window.bbs1AttachmentUpload?.afterSubmit();
        const successMessage = data.tip && data.tip !== data.message
            ? `${data.message || "操作完成"}，${data.tip}`
            : (data.message || data.tip || "操作完成");
        if (data.modal && typeof data.modal === "object") {
            openModal(data.modal.title || data.message || "提示", data.modal.html || "");
            resetButton();
            return;
        }
        if (modalForm) closeModal();
        const replaceTarget = form.dataset.replaceTarget || "";
        const replaceEl = replaceTarget ? (form.closest(replaceTarget) || document.querySelector(replaceTarget)) : null;
        if (data.refresh && replaceEl) {
            try {
                const panelResponse = await fetch(window.location.href, {credentials: "same-origin"});
                const panelDoc = new DOMParser().parseFromString(await panelResponse.text(), "text/html");
                const panel = panelDoc.querySelector(replaceTarget);
                if (panel) replaceEl.outerHTML = panel.outerHTML;
            } catch (_) {}
            if (!replaceEl || replaceEl.isConnected) resetButton();
            showToast(successMessage);
            return;
        }
        showToast(successMessage);
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
        resetButton();
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
