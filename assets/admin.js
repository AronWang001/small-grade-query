(() => {
  'use strict';

  const body = document.body;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');
  const modalTriggers = new WeakMap();
  const toastHost = document.getElementById('toastHost');
  const popoverSupported = toastHost instanceof HTMLElement
    && typeof toastHost.showPopover === 'function'
    && typeof toastHost.hidePopover === 'function';

  const activeDialog = () => {
    const dialogs = Array.from(document.querySelectorAll('dialog.modal[open]'));
    return dialogs.length ? dialogs[dialogs.length - 1] : null;
  };

  const ensureToastHost = () => {
    if (!(toastHost instanceof HTMLElement)) return;
    if (popoverSupported) {
      try {
        if (toastHost.matches(':popover-open')) toastHost.hidePopover();
        toastHost.showPopover();
      } catch (_) {
        // A browser can expose the API while disabling it; use the dialog fallback below.
      }
      if (toastHost.matches(':popover-open')) return;
    }
    const dialog = activeDialog();
    (dialog || body).appendChild(toastHost);
    toastHost.classList.toggle('toast-host-fallback', Boolean(dialog));
  };

  const placeToast = toast => {
    if (!(toast instanceof HTMLElement) || !(toastHost instanceof HTMLElement)) return;
    toastHost.appendChild(toast);
    ensureToastHost();
  };

  const syncModalErrorSummary = dialog => {
    if (!(dialog instanceof HTMLDialogElement)) return null;
    const errorToast = toastHost?.querySelector('.toast-error[data-toast]');
    const message = errorToast?.dataset.toastMessage || errorToast?.querySelector('p')?.textContent?.trim() || '';
    if (!message) return null;
    let summary = dialog.querySelector('.modal-error-summary');
    if (!(summary instanceof HTMLElement)) {
      summary = document.createElement('div');
      summary.className = 'modal-error-summary';
      summary.setAttribute('role', 'alert');
      summary.setAttribute('tabindex', '-1');
      const title = document.createElement('strong');
      title.textContent = '保存失败';
      const text = document.createElement('p');
      summary.append(title, text);
      const bodyContainer = dialog.querySelector('.modal-body');
      if (bodyContainer) bodyContainer.prepend(summary);
      else dialog.querySelector('.modal-card')?.prepend(summary);
    }
    const text = summary.querySelector('p');
    if (text) text.textContent = message;
    dialog.querySelectorAll(':invalid').forEach(field => {
      field.setAttribute('aria-invalid', 'true');
      if (!field.parentElement?.querySelector('.field-error')) {
        const fieldError = document.createElement('p');
        fieldError.className = 'field-error';
        fieldError.textContent = field.validationMessage || '请检查此字段。';
        field.parentElement?.appendChild(fieldError);
      }
    });
    return summary;
  };

  const visibleFocusable = container => Array.from(container.querySelectorAll(focusableSelector))
    .filter(element => !element.hidden && element.getClientRects().length > 0);

  const updateBodyModalState = () => {
    body.classList.toggle('modal-open', Boolean(document.querySelector('dialog.modal[open]')));
  };

  const openModal = (dialog, trigger = null) => {
    if (!(dialog instanceof HTMLDialogElement)) return;
    if (trigger instanceof HTMLElement) modalTriggers.set(dialog, trigger);
    dialog.classList.remove('is-closing');
    if (!dialog.open) {
      try {
        dialog.showModal();
      } catch (_) {
        dialog.setAttribute('open', '');
      }
    }
    body.classList.add('modal-open');
    ensureToastHost();
    window.requestAnimationFrame(() => {
      const errorSummary = syncModalErrorSummary(dialog);
      const target = errorSummary || dialog.querySelector('[autofocus]') || visibleFocusable(dialog)[0];
      if (target instanceof HTMLElement) target.focus({ preventScroll: true });
      if (errorSummary) errorSummary.scrollIntoView({ block: 'nearest' });
    });
  };

  const finishCloseModal = dialog => {
    if (dialog.open) dialog.close();
    dialog.classList.remove('is-closing');
    delete dialog.dataset.submitting;
    updateBodyModalState();
    ensureToastHost();
    const trigger = modalTriggers.get(dialog);
    if (trigger instanceof HTMLElement && document.contains(trigger)) {
      trigger.focus({ preventScroll: true });
    }
  };

  const closeModal = (dialog, immediate = false) => {
    if (!(dialog instanceof HTMLDialogElement) || !dialog.open) return;
    if (dialog.dataset.dismissible === 'false' || dialog.dataset.submitting === 'true') return;
    if (immediate || reduceMotion) {
      finishCloseModal(dialog);
      return;
    }
    dialog.classList.add('is-closing');
    window.setTimeout(() => finishCloseModal(dialog), 180);
  };

  document.querySelectorAll('dialog.modal').forEach(dialog => {
    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      closeModal(dialog);
    });
    dialog.addEventListener('click', event => {
      if (event.target === dialog) closeModal(dialog);
    });
    dialog.addEventListener('keydown', event => {
      if (event.key !== 'Tab') return;
      const focusable = visibleFocusable(dialog);
      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  });

  document.addEventListener('click', event => {
    const closeButton = event.target.closest('[data-modal-close]');
    if (closeButton) {
      const dialog = closeButton.closest('dialog');
      if (dialog) closeModal(dialog);
      return;
    }

    const trigger = event.target.closest('[data-modal-target]');
    if (!(trigger instanceof HTMLAnchorElement) || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const selector = trigger.dataset.modalTarget;
    const dialog = selector ? document.querySelector(selector) : null;
    if (dialog instanceof HTMLDialogElement) {
      event.preventDefault();
      openModal(dialog, trigger);
    }
  });

  document.querySelectorAll('dialog[data-auto-open]').forEach(dialog => {
    if (dialog.open) dialog.removeAttribute('open');
    openModal(dialog);
  });
  ensureToastHost();

  const confirmDialog = document.getElementById('confirmModal');
  const confirmTitle = document.getElementById('confirmTitle');
  const confirmDescription = document.getElementById('confirmDescription');
  const confirmCancel = document.getElementById('confirmCancel');
  const confirmProceed = document.getElementById('confirmProceed');
  let pendingForm = null;
  let pendingSubmitter = null;

  const selectedRecordCount = form => {
    const ids = new Set(
      Array.from(document.querySelectorAll('input[name="record_ids[]"]:checked'))
        .filter(input => input.form === form)
        .map(input => input.value)
    );
    return ids.size;
  };

  const confirmationContent = form => {
    let title = form.dataset.confirmTitle || '确认操作';
    let message = form.dataset.confirmMessage || '请确认是否继续此操作。';
    let label = form.dataset.confirmLabel || '确认';
    let tone = form.dataset.confirmTone || 'danger';

    if (form.dataset.confirmKind === 'bulk-delete') {
      const count = selectedRecordCount(form);
      if (count < 1) {
        showClientToast('请至少选择一条需要删除的记录。', 'warning');
        return null;
      }
      message = '将删除已选择的 ' + count + ' 条记录。删除后记录不会在前台查询中出现。';
    }

    if (form.dataset.confirmKind === 'export') {
      const includesSensitive = Boolean(form.querySelector('[data-sensitive-export]:checked'));
      title = includesSensitive ? '导出完整敏感字段' : '确认导出数据';
      message = includesSensitive
        ? '导出文件将包含完整敏感字段。请确认当前环境安全，并在使用后妥善保管或销毁文件。'
        : '将按当前设置生成Excel文件，并记录本次导出操作。';
      tone = includesSensitive ? 'danger' : 'primary';
    }

    return { title, message, label, tone };
  };

  const showConfirmation = (form, submitter) => {
    if (!(confirmDialog instanceof HTMLDialogElement)) return false;
    const content = confirmationContent(form);
    if (!content) return true;
    pendingForm = form;
    pendingSubmitter = submitter instanceof HTMLElement ? submitter : null;
    confirmTitle.textContent = content.title;
    confirmDescription.textContent = content.message;
    confirmProceed.textContent = content.label;
    confirmProceed.className = 'btn ' + (content.tone === 'primary' ? 'btn-primary' : 'btn-danger');
    openModal(confirmDialog, submitter instanceof HTMLElement ? submitter : form);
    window.requestAnimationFrame(() => confirmCancel?.focus());
    return true;
  };

  confirmCancel?.addEventListener('click', () => {
    pendingForm = null;
    pendingSubmitter = null;
    closeModal(confirmDialog);
  });

  confirmProceed?.addEventListener('click', () => {
    if (!(pendingForm instanceof HTMLFormElement)) {
      closeModal(confirmDialog);
      return;
    }
    const form = pendingForm;
    const submitter = pendingSubmitter;
    pendingForm = null;
    pendingSubmitter = null;
    form.dataset.confirmed = 'true';
    finishCloseModal(confirmDialog);
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit(submitter instanceof HTMLButtonElement ? submitter : undefined);
    } else {
      form.submit();
    }
  });

  const setSubmitting = (form, submitter) => {
    const dialog = form.closest('dialog');
    if (dialog) dialog.dataset.submitting = 'true';
    const button = submitter instanceof HTMLButtonElement
      ? submitter
      : form.querySelector('button[type="submit"], input[type="submit"]');
    if (button instanceof HTMLButtonElement) {
      button.classList.add('is-loading');
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (form.action.includes('admin_download.php')) {
        window.setTimeout(() => {
          button.classList.remove('is-loading');
          button.disabled = false;
          button.removeAttribute('aria-busy');
          if (dialog) delete dialog.dataset.submitting;
        }, 1800);
      }
    }
  };

  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.dataset.confirmed === 'true') {
      delete form.dataset.confirmed;
      setSubmitting(form, event.submitter);
      return;
    }

    if (form.dataset.confirmTitle || form.dataset.confirmKind) {
      event.preventDefault();
      showConfirmation(form, event.submitter);
      return;
    }

    setSubmitting(form, event.submitter);
  });

  document.querySelectorAll('.bulk-toggle').forEach(toggle => {
    toggle.addEventListener('change', () => {
      const checked = toggle.checked;
      document.querySelectorAll('input[name="record_ids[]"]').forEach(box => {
        box.checked = checked;
      });
      document.querySelectorAll('.bulk-toggle').forEach(other => {
        other.checked = checked;
      });
    });
  });

  const sidebar = document.getElementById('adminSidebar');
  const sidebarCollapse = document.getElementById('sidebarCollapse');
  const mobileMenuButton = document.getElementById('mobileMenuButton');
  const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
  const sidebarStorageKey = 'gradeQuerySidebarCollapsed';

  try {
    if (window.localStorage.getItem(sidebarStorageKey) === '1') {
      body.classList.add('sidebar-collapsed');
      sidebarCollapse?.setAttribute('aria-expanded', 'false');
      sidebarCollapse?.setAttribute('aria-label', '展开侧边栏');
    }
  } catch (_) {
    // Local storage may be blocked; navigation remains fully usable.
  }

  sidebarCollapse?.addEventListener('click', () => {
    const collapsed = body.classList.toggle('sidebar-collapsed');
    sidebarCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    sidebarCollapse.setAttribute('aria-label', collapsed ? '展开侧边栏' : '收起侧边栏');
    try {
      window.localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0');
    } catch (_) {
      // Ignore storage failures.
    }
  });

  const openMobileNav = () => {
    if (!sidebar) return;
    body.classList.add('mobile-nav-open');
    mobileNavBackdrop.hidden = false;
    mobileMenuButton?.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(() => visibleFocusable(sidebar)[0]?.focus());
  };

  const closeMobileNav = () => {
    body.classList.remove('mobile-nav-open');
    if (mobileNavBackdrop) mobileNavBackdrop.hidden = true;
    mobileMenuButton?.setAttribute('aria-expanded', 'false');
    mobileMenuButton?.focus({ preventScroll: true });
  };

  mobileMenuButton?.addEventListener('click', () => {
    if (body.classList.contains('mobile-nav-open')) closeMobileNav();
    else openMobileNav();
  });
  mobileNavBackdrop?.addEventListener('click', closeMobileNav);
  sidebar?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    if (window.matchMedia('(max-width: 960px)').matches) closeMobileNav();
  }));

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      const openDialogs = Array.from(document.querySelectorAll('dialog.modal[open]'));
      const activeDialog = openDialogs[openDialogs.length - 1];
      if (activeDialog instanceof HTMLDialogElement) {
        event.preventDefault();
        closeModal(activeDialog);
        return;
      }
    }
    if (event.key === 'Escape' && body.classList.contains('mobile-nav-open')) {
      event.preventDefault();
      closeMobileNav();
    }
    if (event.key === 'Tab' && body.classList.contains('mobile-nav-open') && sidebar) {
      const focusable = visibleFocusable(sidebar);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  const adminMenu = document.getElementById('adminMenu');
  document.addEventListener('click', event => {
    if (adminMenu?.open && !adminMenu.contains(event.target)) adminMenu.open = false;
    document.querySelectorAll('.action-menu[open]').forEach(menu => {
      if (!menu.contains(event.target)) menu.open = false;
    });
  });

  const updateFieldSettings = select => {
    const modal = select.closest('dialog') || document;
    const type = select.value;
    modal.querySelectorAll('[data-select-setting]').forEach(element => {
      element.hidden = type !== 'select';
    });
    modal.querySelectorAll('[data-number-setting]').forEach(element => {
      element.hidden = type !== 'number';
    });
  };

  document.querySelectorAll('[data-field-type]').forEach(select => {
    updateFieldSettings(select);
    select.addEventListener('change', () => updateFieldSettings(select));
  });

  document.querySelectorAll('[data-import-mode]').forEach(select => {
    const warning = select.closest('form')?.querySelector('[data-replace-warning]');
    const update = () => {
      if (warning) warning.hidden = select.value !== 'replace';
    };
    update();
    select.addEventListener('change', update);
  });

  document.querySelectorAll('input[name="export_scope"]').forEach(radio => {
    radio.addEventListener('change', () => {
      const form = radio.closest('form');
      const searchGroup = form?.querySelector('[data-export-search]');
      const searchInput = searchGroup?.querySelector('input');
      const filtered = form?.querySelector('input[name="export_scope"]:checked')?.value === 'filtered';
      if (searchGroup) searchGroup.hidden = !filtered;
      if (searchInput) {
        searchInput.required = filtered;
        if (!filtered) searchInput.value = '';
      }
    });
  });

  document.querySelectorAll('[data-sensitive-export]').forEach(checkbox => {
    const form = checkbox.closest('form');
    const acknowledgement = form?.querySelector('[data-sensitive-ack]');
    const acknowledgementInput = acknowledgement?.querySelector('input');
    const update = () => {
      if (acknowledgement) acknowledgement.hidden = !checkbox.checked;
      if (acknowledgementInput) {
        acknowledgementInput.required = checkbox.checked;
        if (!checkbox.checked) acknowledgementInput.checked = false;
      }
    };
    update();
    checkbox.addEventListener('change', update);
  });

  document.querySelectorAll('[data-auto-submit]').forEach(select => {
    select.addEventListener('change', () => select.form?.requestSubmit());
  });

  const removeToast = toast => {
    if (!(toast instanceof HTMLElement) || toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), reduceMotion ? 0 : 190);
  };

  document.querySelectorAll('[data-toast]').forEach(toast => {
    toast.querySelector('[data-toast-close]')?.addEventListener('click', () => removeToast(toast));
    const duration = toast.classList.contains('toast-error') ? 12000 : (toast.classList.contains('toast-success') ? 4500 : 6000);
    window.setTimeout(() => removeToast(toast), duration);
  });

  function showClientToast(message, type = 'warning') {
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    toast.dataset.toast = '';
    toast.dataset.toastMessage = message;
    const content = document.createElement('div');
    const title = document.createElement('strong');
    const text = document.createElement('p');
    title.textContent = type === 'error' ? '操作失败' : '请注意';
    text.textContent = message;
    content.append(title, text);
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', '关闭提示');
    close.textContent = '×';
    close.addEventListener('click', () => removeToast(toast));
    toast.append(document.createElement('span'), content, close);
    placeToast(toast);
    window.setTimeout(() => removeToast(toast), type === 'error' ? 12000 : (type === 'success' ? 4500 : 6000));
  }

  document.querySelectorAll('[data-schema-mapping]').forEach(select => {
    const row = select.closest('tr');
    const update = () => {
      if (!row) return;
      const existing = select.value.startsWith('existing:');
      const ignored = select.value === 'ignore';
      row.classList.toggle('is-ignored', ignored);
      row.querySelectorAll('input:not([type="hidden"]), select:not([data-schema-mapping])').forEach(control => {
        control.disabled = existing || ignored;
      });
      const label = row.querySelector('input[name$="[field_label]"]');
      if (label) label.readOnly = existing || ignored;
    };
    select.addEventListener('change', update);
    update();
  });

  document.querySelectorAll('[data-permanent-delete-form]').forEach(form => {
    const acknowledge = form.querySelector('input[name="acknowledge_irreversible"]');
    const projectName = form.querySelector('input[name="project_name_confirmation"]');
    const deleteText = form.querySelector('input[name="delete_confirmation"]');
    const submit = form.querySelector('[data-permanent-delete-submit]');
    const update = () => {
      if (!(submit instanceof HTMLButtonElement)) return;
      submit.disabled = !acknowledge?.checked
        || projectName?.value.trim() !== projectName?.dataset.expected
        || deleteText?.value.trim() !== 'DELETE';
    };
    [acknowledge, projectName, deleteText].forEach(control => {
      control?.addEventListener('input', update);
      control?.addEventListener('change', update);
    });
    update();
  });

  document.querySelectorAll('[data-password-reset-form]').forEach(form => {
    const manualField = form.querySelector('[data-manual-password]');
    const manualInput = manualField?.querySelector('input');
    const update = () => {
      const isManual = form.querySelector('input[name="password_mode"]:checked')?.value === 'manual';
      if (manualField) manualField.hidden = !isManual;
      if (manualInput) {
        manualInput.required = isManual;
        if (!isManual) manualInput.value = '';
      }
    };
    form.querySelectorAll('input[name="password_mode"]').forEach(radio => radio.addEventListener('change', update));
    update();
  });

  document.querySelectorAll('[data-copy-secret]').forEach(button => {
    button.addEventListener('click', async () => {
      const secret = button.closest('.one-time-secret')?.querySelector('code')?.textContent || '';
      if (!secret) return;
      try {
        await navigator.clipboard.writeText(secret);
        button.textContent = '已复制';
        button.classList.add('is-success');
        window.setTimeout(() => {
          button.textContent = '复制临时密码';
          button.classList.remove('is-success');
        }, 1800);
      } catch (_) {
        showClientToast('浏览器未允许自动复制，请手动选择临时密码。', 'warning');
      }
    });
  });

  const settingsForm = document.getElementById('turnstileSettingsForm');
  const settingsMaster = settingsForm?.querySelector('[data-turnstile-master]');
  const settingsDependent = settingsForm?.querySelector('[data-turnstile-dependent]');
  const updateTurnstileDependents = () => {
    if (!settingsDependent || !settingsMaster) return;
    settingsDependent.classList.toggle('is-inactive', !settingsMaster.checked);
    settingsDependent.setAttribute('aria-disabled', settingsMaster.checked ? 'false' : 'true');
  };
  settingsMaster?.addEventListener('change', updateTurnstileDependents);
  updateTurnstileDependents();

  const turnstileTestButton = document.querySelector('[data-test-turnstile]');
  const turnstileTestDialog = document.getElementById('turnstileTestModal');
  const turnstileTestHost = turnstileTestDialog?.querySelector('[data-turnstile-test-widget]');
  const turnstileTestResult = turnstileTestDialog?.querySelector('[data-turnstile-test-result]');
  let turnstileTestWidgetId = null;

  const setTurnstileTestResult = (message, type = '') => {
    if (!turnstileTestResult) return;
    turnstileTestResult.className = 'turnstile-test-result' + (type ? ' is-' + type : '');
    turnstileTestResult.replaceChildren(Object.assign(document.createElement('p'), { textContent: message }));
  };

  const submitTurnstileTest = async token => {
    if (!(settingsForm instanceof HTMLFormElement)) return;
    setTurnstileTestResult('正在向Cloudflare验证当前配置…', 'loading');
    const data = new FormData(settingsForm);
    data.set('action', 'test_turnstile_settings');
    data.set('ajax', '1');
    data.set('turnstile_test_token', token);
    try {
      const response = await fetch('admin.php?tab=system_settings', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const result = await response.json();
      setTurnstileTestResult(result.message || '配置测试失败，请稍后重试。', result.success ? 'success' : 'error');
      if (!result.success && window.turnstile && turnstileTestWidgetId !== null) {
        window.turnstile.reset(turnstileTestWidgetId);
      }
    } catch (_) {
      setTurnstileTestResult('配置测试失败，请检查网络后重试。', 'error');
      if (window.turnstile && turnstileTestWidgetId !== null) window.turnstile.reset(turnstileTestWidgetId);
    }
  };

  const renderTurnstileTest = () => {
    if (!turnstileTestHost || !settingsForm) return;
    const siteKey = settingsForm.querySelector('[name="site_key"]')?.value.trim() || '';
    if (!siteKey) {
      setTurnstileTestResult('请先填写Site Key，再进行配置测试。', 'error');
      return;
    }
    if (!window.turnstile) {
      setTurnstileTestResult('人机验证组件尚未加载，请检查网络后重试。', 'error');
      return;
    }
    if (turnstileTestWidgetId !== null) {
      try { window.turnstile.remove(turnstileTestWidgetId); } catch (_) { /* Widget may already be gone. */ }
      turnstileTestWidgetId = null;
    }
    turnstileTestHost.replaceChildren();
    setTurnstileTestResult('请完成人机验证，系统会自动提交测试。');
    try {
      turnstileTestWidgetId = window.turnstile.render(turnstileTestHost, {
        sitekey: siteKey,
        theme: settingsForm.querySelector('[name="theme"]')?.value || 'auto',
        size: settingsForm.querySelector('[name="size"]')?.value || 'normal',
        action: 'admin_settings_test',
        callback: submitTurnstileTest,
        'error-callback': () => setTurnstileTestResult('人机验证加载失败，请检查Site Key和网络。', 'error'),
        'expired-callback': () => setTurnstileTestResult('验证已过期，请重新完成人机验证。', 'error')
      });
    } catch (_) {
      setTurnstileTestResult('无法渲染人机验证组件，请检查Site Key。', 'error');
    }
  };

  turnstileTestButton?.addEventListener('click', () => {
    if (turnstileTestDialog instanceof HTMLDialogElement) {
      openModal(turnstileTestDialog, turnstileTestButton);
      window.setTimeout(renderTurnstileTest, 80);
    }
  });

  if (!reduceMotion) {
    document.querySelectorAll('[data-count]').forEach(element => {
      const target = Number(element.dataset.count || 0);
      const suffix = element.dataset.countSuffix || '';
      if (!Number.isFinite(target) || target < 0) return;
      const startedAt = performance.now();
      const duration = 520;
      const tick = now => {
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        element.textContent = String(Math.round(target * eased)) + suffix;
        if (progress < 1) window.requestAnimationFrame(tick);
      };
      element.textContent = '0' + suffix;
      window.requestAnimationFrame(tick);
    });
  }
})();

/* ============================================================
   新建查询向导 JavaScript
   ============================================================ */
(function() {
  "use strict";

  var wizard = document.getElementById("queryWizard");
  if (!wizard) return;

  var state = {
    currentStep: 1, draftId: 0, token: "",
    columns: [], previewRows: [], totalRows: 0,
    suggestedName: "", conditions: [], results: [], stepData: {}
  };

  var csrfToken = document.getElementById("wizardCsrfToken")?.value || "";
  var TOTAL_STEPS = 5;
  var panels = document.getElementById("wizardPanels");
  var successPanel = document.getElementById("wizardSuccess");
  var footer = document.getElementById("wizardFooter");
  var loading = document.getElementById("wizardLoading");
  var prevBtn = document.getElementById("wizardPrev");
  var nextBtn = document.getElementById("wizardNext");
  var publishBtn = document.getElementById("wizardPublish");
  var cancelBtn = document.getElementById("wizardCancel");
  var confirmDialog = document.getElementById("confirmPublishDialog");
  var confirmPublishBtn = document.getElementById("confirmPublishBtn");

  function showToast(message, type) {
    type = type || "error";
    var host = document.getElementById("toastHost");
    if (!host) return;
    var toast = document.createElement("div");
    toast.className = "toast toast-" + type;
    toast.setAttribute("role", type === "error" ? "alert" : "status");
    toast.setAttribute("data-toast", "");
    toast.setAttribute("data-toast-message", message);
    var iconSvg = type === "success"
      ? '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>'
      : '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg>';
    toast.innerHTML = '<span class="toast-icon">' + iconSvg + '</span><div><strong>' +
      (type === "success" ? "操作成功" : "操作失败") + '</strong><p>' + message + '</p></div>' +
      '<button type="button" data-toast-close aria-label="关闭提示"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
    host.appendChild(toast);
    var popoverSupported = host instanceof HTMLElement && typeof host.showPopover === "function";
    if (popoverSupported) {
      try { if (host.matches(":popover-open")) host.hidePopover(); host.showPopover(); } catch(_) {}
    } else { document.body.appendChild(host); }
    var closeBtn = toast.querySelector("[data-toast-close]");
    if (closeBtn) closeBtn.addEventListener("click", function() { toast.remove(); });
    setTimeout(function() { toast.remove(); }, 5000);
  }

  function showLoading(text) {
    if (!loading) return;
    loading.hidden = false;
    loading.style.display = "flex";
    var t = document.getElementById("wizardLoadingText");
    if (t) t.textContent = text || "处理中...";
  }
  function hideLoading() { if (loading) { loading.hidden = true; loading.style.display = "none"; } }

  async function wizardFetch(action, formData) {
    formData = formData || new FormData();
    formData.set("csrf_token", csrfToken);
    formData.set("ajax", "1");
    formData.set("action", action);
    var resp = await fetch("admin.php?tab=new_query", {
      method: "POST", body: formData, credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest" }
    });
    if (!resp.ok) throw new Error("服务器返回错误 (" + resp.status + ")");
    var result = await resp.json();
    if (!result.success) throw new Error(result.message || "操作失败");
    return result;
  }

  function goToStep(step) {
    if (step < 1 || step > TOTAL_STEPS) return;
    state.currentStep = step;
    updateUI();
  }

  function updateUI() {
    var s = state.currentStep;
    panels.querySelectorAll(".wizard-panel").forEach(function(p) {
      p.classList.toggle("active", parseInt(p.dataset.panel) === s);
    });
    wizard.querySelectorAll(".wizard-step-item").forEach(function(item) {
      var n = parseInt(item.dataset.step);
      item.classList.remove("active", "completed");
      if (n === s) item.classList.add("active");
      else if (n < s) item.classList.add("completed");
    });
    var bar = wizard.querySelector(".wizard-step-progress-bar");
    if (bar) bar.style.width = ((s - 1) / (TOTAL_STEPS - 1) * 100) + "%";
    var ms = wizard.querySelector(".wizard-mobile-step");
    if (ms) ms.textContent = "步骤 " + s + "/" + TOTAL_STEPS;
    if (prevBtn) prevBtn.hidden = (s === 1);
    if (nextBtn) nextBtn.hidden = (s === TOTAL_STEPS);
    if (publishBtn) { publishBtn.hidden = false; publishBtn.disabled = (s !== TOTAL_STEPS); publishBtn.classList.toggle('btn-disabled', s !== TOTAL_STEPS); }
    if (nextBtn) nextBtn.disabled = (s === 1 && state.draftId === 0);
    if (s === 2) buildFieldsTable();
    if (s === 3) buildQuerySettings();
    if (s === 5) buildPublishSummary();
    wizard.scrollIntoView({ behavior: "smooth", block: "start" });
  }
  function escapeHtml(str) {
    var div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  function maskSensitive(val) {
    if (!val || val.length <= 4) return "***";
    return val.substring(0, 2) + "***" + val.substring(val.length - 2);
  }

  // ===== 步骤1：上传 =====
  function initStep1() {
    var zone = document.getElementById("uploadZone");
    var input = document.getElementById("excelFileInput");
    var reupload = document.getElementById("reuploadBtn");
    if (!zone || !input) return;
    zone.addEventListener("click", function(e) { if (e.target !== input) input.click(); });
    input.addEventListener("change", function() {
      if (input.files && input.files.length > 0) handleFileUpload(input.files[0]);
    });
    zone.addEventListener("dragover", function(e) { e.preventDefault(); zone.classList.add("drag-over"); });
    zone.addEventListener("dragleave", function(e) { e.preventDefault(); zone.classList.remove("drag-over"); });
    zone.addEventListener("drop", function(e) {
      e.preventDefault(); zone.classList.remove("drag-over");
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) handleFileUpload(e.dataTransfer.files[0]);
    });
    if (reupload) reupload.addEventListener("click", function() { input.value = ""; input.click(); });

    wizard.querySelectorAll("[data-action=\"resume-draft\"]").forEach(function(btn) {
      btn.addEventListener("click", function() { resumeDraft(parseInt(btn.dataset.draftId), btn.dataset.draftToken); });
    });
    wizard.querySelectorAll("[data-action=\"delete-draft\"]").forEach(function(btn) {
      btn.addEventListener("click", function() { deleteDraft(parseInt(btn.dataset.draftId), btn.dataset.draftToken); });
    });
  }

  async function handleFileUpload(file) {
    var ext = file.name.split(".").pop().toLowerCase();
    if (ext !== "xlsx" && ext !== "xls") { showToast("仅支持 .xlsx 和 .xls 文件。"); return; }
    if (file.size > 5242880) { showToast("文件大小超过 5MB 限制。"); return; }
    showLoading("正在解析 Excel 文件...");
    try {
      var fd = new FormData(); fd.append("excel_file", file);
      var result = await wizardFetch("new_query_step1", fd);
      state.draftId = result.draft_id; state.token = result.token;
      state.columns = result.columns || []; state.previewRows = result.preview_rows || [];
      state.totalRows = result.total_rows || 0; state.suggestedName = result.suggested_name || "";
      var fi = document.getElementById("fileInfo");
      if (fi) { fi.hidden = false;
        document.getElementById("fileName").textContent = file.name;
        document.getElementById("fileMeta").textContent = "工作表：" + (result.sheet_name || "Sheet1") +
          " · 字段：" + state.columns.length + " · 数据行：" + state.totalRows + " · " + (file.size / 1048576).toFixed(2) + " MB";
      }
      showUploadPreview(result);
      if (nextBtn) nextBtn.disabled = (result.errors && result.errors.length > 0);
      hideLoading();
      updateUI();
      showToast("Excel 解析成功，已识别 " + state.columns.length + " 个字段、" + state.totalRows + " 条数据。", "success");
    } catch (e) { hideLoading(); showToast(e.message || "文件解析失败"); }
  }

  function showUploadPreview(result) {
    var preview = document.getElementById("uploadPreview");
    var stats = document.getElementById("previewStats");
    var tableWrap = document.getElementById("previewTableWrap");
    var warnDiv = document.getElementById("previewWarnings");
    var errDiv = document.getElementById("previewErrors");
    if (!preview) return; preview.hidden = false;
    if (stats) stats.innerHTML =
      '<div class="wizard-preview-stat"><strong>' + (result.columns ? result.columns.length : 0) + '</strong>字段</div>' +
      '<div class="wizard-preview-stat"><strong>' + (result.total_rows || 0) + '</strong>条数据</div>' +
      '<div class="wizard-preview-stat"><strong>' + (result.sheet_name || "Sheet1") + '</strong>工作表</div>';
    if (tableWrap && result.columns && result.preview_rows) {
      var h = '<table><thead><tr>';
      result.columns.forEach(function(c) { h += '<th>' + escapeHtml(c.header) + (c.sensitive ? ' <span class="wizard-sensitive-badge">敏感</span>' : '') + '</th>'; });
      h += '</tr></thead><tbody>';
      result.preview_rows.forEach(function(row) {
        h += '<tr>';
        row.forEach(function(val, i) {
          var s = result.columns[i] && result.columns[i].sensitive;
          h += '<td class="' + (s ? 'sensitive-cell' : '') + '">' + escapeHtml(s ? maskSensitive(String(val)) : String(val)) + '</td>';
        });
        h += '</tr>';
      });
      h += '</tbody></table>'; tableWrap.innerHTML = h;
    }
    if (warnDiv) { warnDiv.hidden = !(result.warnings && result.warnings.length); if (!warnDiv.hidden) warnDiv.innerHTML = result.warnings.map(function(w) { return '<div>' + escapeHtml(w) + '</div>'; }).join(''); }
    if (errDiv) { errDiv.hidden = !(result.errors && result.errors.length); if (!errDiv.hidden) errDiv.innerHTML = result.errors.map(function(e) { return '<div>' + escapeHtml(e) + '</div>'; }).join(''); }
  }

  // ===== 步骤2：字段配置 =====
  async function buildFieldsTable() {
    if (state.draftId > 0) { try { await saveStep2(); } catch(e) {} }
    var tbody = document.getElementById("fieldsTableBody");
    if (!tbody || !state.columns.length) return;
    var summary = document.getElementById("step2Summary");
    if (summary) summary.textContent = "已识别 " + state.columns.length + " 个字段、" + state.totalRows + " 条数据。您可以调整字段类型和敏感属性。";
    var h = '';
    state.columns.forEach(function(col, idx) {
      var eg = '';
      if (state.previewRows.length > 0 && state.previewRows[0][idx] !== undefined) {
        var raw = String(state.previewRows[0][idx] || '');
        eg = col.sensitive ? maskSensitive(raw) : raw;
      }
      h += '<tr><td data-label="表格字段"><strong>' + escapeHtml(col.header) + '</strong><br><small style="color:var(--muted)">标识：' + escapeHtml(col.key) + '</small></td>';
      h += '<td data-label="示例数据">' + escapeHtml(eg || '-') + '</td>';
      h += '<td data-label="字段类型"><select class="wizard-field-type" data-idx="' + idx + '">' +
        '<option value="text"' + (col.type === 'text' ? ' selected' : '') + '>文本</option>' +
        '<option value="number"' + (col.type === 'number' ? ' selected' : '') + '>数字</option>' +
        '<option value="date"' + (col.type === 'date' ? ' selected' : '') + '>日期</option>' +
        '<option value="select"' + (col.type === 'select' ? ' selected' : '') + '>下拉选择</option>' +
        '</select></td>';
      h += '<td data-label="敏感"><label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" class="wizard-field-sensitive" data-idx="' + idx + '"' + (col.sensitive ? ' checked' : '') + '><span style="font-size:13px">' + (col.sensitive ? '是' : '否') + '</span></label></td>';
      h += '<td class="wizard-advanced-col" data-label="高级"><details><summary style="font-size:12px;color:var(--muted);cursor:pointer">展开</summary><div style="margin-top:6px;font-size:12px"><label>小数位：<input type="number" class="wizard-field-decimals" data-idx="' + idx + '" value="' + (col.decimal_places || 2) + '" min="0" max="6" style="width:60px" ' + (col.type !== 'number' ? 'disabled' : '') + '></label></div></details></td></tr>';
    });
    tbody.innerHTML = h;
    tbody.querySelectorAll(".wizard-field-type").forEach(function(sel) {
      sel.addEventListener("change", function() {
        var idx = parseInt(sel.dataset.idx); state.columns[idx].type = sel.value;
        var dec = tbody.querySelector('.wizard-field-decimals[data-idx="' + idx + '"]');
        if (dec) dec.disabled = (sel.value !== 'number');
      });
    });
    tbody.querySelectorAll(".wizard-field-sensitive").forEach(function(cb) {
      cb.addEventListener("change", function() {
        var idx = parseInt(cb.dataset.idx); state.columns[idx].sensitive = cb.checked;
        cb.nextElementSibling.textContent = cb.checked ? '是' : '否';
      });
    });
    tbody.querySelectorAll(".wizard-field-decimals").forEach(function(inp) {
      inp.addEventListener("change", function() {
        var idx = parseInt(inp.dataset.idx); state.columns[idx].decimal_places = parseInt(inp.value) || 0;
      });
    });
  }

  async function saveStep2() {
    if (!state.draftId) return;
    var fd = new FormData(); fd.append("draft_id", state.draftId); fd.append("token", state.token);
    fd.append("columns", JSON.stringify(state.columns));
    var result = await wizardFetch("new_query_step2", fd);
    if (result.recommended_conditions) state.stepData.recommended_conditions = result.recommended_conditions;
  }

  // ===== 步骤3：查询设置 =====
  function buildQuerySettings() {
    var condDiv = document.getElementById("conditionFields");
    var resultDiv = document.getElementById("resultFields");
    if (!condDiv || !resultDiv) return;
    var recommended = state.stepData.recommended_conditions || [];
    var recommendedCols = []; recommended.forEach(function(rc) { recommendedCols.push(rc.column_index); });
    if (state.conditions.length === 0 && recommendedCols.length > 0) state.conditions = recommendedCols.slice();
    if (state.results.length === 0) state.columns.forEach(function(col) { if (!col.sensitive) state.results.push(col.column_index); });

    var condH = '';
    state.columns.forEach(function(col) {
      var checked = state.conditions.indexOf(col.column_index) !== -1;
      condH += '<div class="wizard-check-item"><input type="checkbox" class="wizard-condition-cb" data-cidx="' + col.column_index + '"' + (checked ? ' checked' : '') + '><label>' + escapeHtml(col.header) + '<span class="wizard-check-badge ' + (col.sensitive ? 'sensitive' : col.type === 'number' ? 'number' : 'text') + '">' + (col.sensitive ? '敏感' : col.type === 'number' ? '数字' : '文本') + '</span></label></div>';
    });
    condDiv.innerHTML = condH;

    var resultH = '';
    state.columns.forEach(function(col) {
      var checked = state.results.indexOf(col.column_index) !== -1;
      resultH += '<div class="wizard-check-item"><input type="checkbox" class="wizard-result-cb" data-cidx="' + col.column_index + '"' + (checked ? ' checked' : '') + '><label>' + escapeHtml(col.header) + '<span class="wizard-check-badge ' + (col.sensitive ? 'sensitive' : col.type === 'number' ? 'number' : 'text') + '">' + (col.sensitive ? '敏感' : col.type === 'number' ? '数字' : '文本') + '</span></label><div class="move-btns"><button type="button" class="move-btn" data-move="up" data-cidx="' + col.column_index + '" title="上移">&#9650;</button><button type="button" class="move-btn" data-move="down" data-cidx="' + col.column_index + '" title="下移">&#9660;</button></div></div>';
    });
    resultDiv.innerHTML = resultH;

    updateSafetyNotice(); updatePreviewForm();

    condDiv.querySelectorAll(".wizard-condition-cb").forEach(function(cb) {
      cb.addEventListener("change", function() {
        var cidx = parseInt(cb.dataset.cidx);
        if (cb.checked) { if (state.conditions.indexOf(cidx) === -1) state.conditions.push(cidx); }
        else { state.conditions = state.conditions.filter(function(c) { return c !== cidx; }); }
        updateSafetyNotice(); updatePreviewForm();
      });
    });
    resultDiv.querySelectorAll(".wizard-result-cb").forEach(function(cb) {
      cb.addEventListener("change", function() {
        var cidx = parseInt(cb.dataset.cidx);
        if (cb.checked) { if (state.results.indexOf(cidx) === -1) state.results.push(cidx); }
        else { state.results = state.results.filter(function(c) { return c !== cidx; }); }
      });
    });
    resultDiv.querySelectorAll(".move-btn").forEach(function(btn) {
      btn.addEventListener("click", function() {
        var cidx = parseInt(btn.dataset.cidx);
        var dir = btn.dataset.move;
        var idx = state.results.indexOf(cidx);
        if (idx === -1) return;
        if (dir === "up" && idx > 0) { var t = state.results[idx-1]; state.results[idx-1] = state.results[idx]; state.results[idx] = t; }
        else if (dir === "down" && idx < state.results.length - 1) { var t = state.results[idx+1]; state.results[idx+1] = state.results[idx]; state.results[idx] = t; }
        buildQuerySettings();
      });
    });
  }

  function updateSafetyNotice() {
    var notice = document.getElementById("safetyNotice"); if (!notice) return;
    var hasStrong = false;
    state.conditions.forEach(function(cidx) {
      var col = state.columns.find(function(c) { return c.column_index === cidx; });
      if (col) { var k = (col.key || "").toLowerCase(); if (k === "id_card" || k === "student_number" || k === "phone") hasStrong = true; }
    });
    notice.hidden = (state.conditions.length > 0 && (hasStrong || state.conditions.length >= 2));
    if (!notice.hidden) notice.querySelector("span").textContent = state.conditions.length === 0 ? "请至少选择一个查询条件。" : "当前查询条件容易匹配到多人或被批量猜测，请增加学号、身份证号、手机号等识别字段。";
  }

  function updatePreviewForm() {
    var container = document.getElementById("previewFormFields");
    var title = document.getElementById("previewFormTitle");
    if (!container) return;
    var nameInput = document.getElementById("wizardName");
    if (title) title.textContent = (nameInput && nameInput.value) || state.suggestedName || "查询项目";
    if (state.conditions.length === 0) { container.innerHTML = '<p style="color:var(--muted);font-size:13px">请至少选择一个查询条件</p>'; return; }
    var h = '';
    state.conditions.forEach(function(cidx) {
      var col = state.columns.find(function(c) { return c.column_index === cidx; });
      if (col) h += '<div class="wizard-preview-form-field"><label>' + escapeHtml(col.header) + '</label><input type="text" placeholder="请输入' + escapeHtml(col.header) + '" disabled></div>';
    });
    container.innerHTML = h;
  }

  // ===== 步骤4：页面设置 =====
  function initStep4() {
    var ni = document.getElementById("wizardName");
    if (ni && !ni.value) ni.value = state.suggestedName || "";
  }

  // ===== 步骤5：发布摘要 =====
  function buildPublishSummary() {
    var summary = document.getElementById("publishSummary");
    var tableWrap = document.getElementById("publishTableWrap");
    if (!summary) return;
    var ni = document.getElementById("wizardName");
    var name = (ni && ni.value) || state.suggestedName || "未命名";
    var pubNow = document.querySelector('input[name="publish_now"]:checked');
    var pubLabel = (pubNow && pubNow.value === "1") ? "立即发布" : "暂存但不开放查询";
    var condCols = [], resultCols = [], sensCols = [];
    state.conditions.forEach(function(cidx) { var c = state.columns.find(function(x) { return x.column_index === cidx; }); if (c) condCols.push(c.header); });
    state.results.forEach(function(cidx) { var c = state.columns.find(function(x) { return x.column_index === cidx; }); if (c) resultCols.push(c.header); });
    state.columns.forEach(function(c) { if (c.sensitive) sensCols.push(c.header); });
    summary.innerHTML = '<dl><dt>查询名称</dt><dd>' + escapeHtml(name) + '</dd><dt>数据数量</dt><dd>' + state.totalRows + ' 条</dd><dt>字段数量</dt><dd>' + state.columns.length + ' 个</dd><dt>查询条件</dt><dd>' + escapeHtml(condCols.join('、') || '未设置') + '</dd><dt>结果字段</dt><dd>' + escapeHtml(resultCols.join('、') || '未设置') + '</dd><dt>敏感字段</dt><dd>' + escapeHtml(sensCols.join('、') || '无') + '</dd><dt>发布方式</dt><dd>' + pubLabel + '</dd></dl>';
    if (tableWrap && state.columns && state.previewRows) {
      var h = '<table><thead><tr>';
      state.columns.forEach(function(c) { h += '<th>' + escapeHtml(c.header) + '</th>'; });
      h += '</tr></thead><tbody>';
      state.previewRows.forEach(function(row) { h += '<tr>'; row.forEach(function(val, i) { var s = state.columns[i] && state.columns[i].sensitive; h += '<td class="' + (s ? 'sensitive-cell' : '') + '">' + escapeHtml(s ? maskSensitive(String(val)) : String(val)) + '</td>'; }); h += '</tr>'; });
      h += '</tbody></table>'; tableWrap.innerHTML = h;
    }
  }

  // ===== 发布 =====
  async function confirmPublish() {
    showLoading("正在创建查询项目并导入数据...");
    try {
      var fd = new FormData(); fd.append("draft_id", state.draftId); fd.append("token", state.token);
      var result = await wizardFetch("new_query_step5", fd);
      if (panels) panels.hidden = true;
      if (successPanel) successPanel.hidden = false;
      if (footer) footer.hidden = true;
      hideLoading();
      var info = document.getElementById("successInfo");
      if (info) info.innerHTML =
        '<p><strong>查询名称：</strong>' + escapeHtml(result.name || '') + '</p>' +
        '<p><strong>数据数量：</strong>' + (result.imported_rows || 0) + ' 条</p>' +
        '<p><strong>字段数量：</strong>' + (result.field_count || 0) + ' 个</p>' +
        '<p><strong>状态：</strong>' + (result.status === 'enabled' ? '已启用' : '已保存（未启用）') + '</p>';
      var fl = document.getElementById("successFrontLink");
      if (fl && result.slug) fl.href = "index.php?project=" + encodeURIComponent(result.slug);
      var cb = document.getElementById("successCopyLink");
      if (cb && result.slug) {
        var url = window.location.origin + "/index.php?project=" + encodeURIComponent(result.slug);
        cb.addEventListener("click", function() {
          navigator.clipboard.writeText(url).then(function() { showToast("链接已复制到剪贴板", "success"); }).catch(function() { showToast("复制失败，请手动复制链接"); });
        });
      }
      showToast("查询已成功发布！共导入 " + (result.imported_rows || 0) + " 条数据。", "success");
    } catch (e) { hideLoading(); showToast(e.message || "发布失败，请重试。"); }
  }

  async function resumeDraft(draftId, token) {
    showLoading("正在恢复草稿...");
    try {
      var fd = new FormData(); fd.append("draft_id", draftId); fd.append("token", token);
      var result = await wizardFetch("new_query_get_draft", fd);
      state.draftId = result.draft_id; state.token = result.token;
      state.suggestedName = result.suggested_name || "";
      state.stepData = result.step_data || {};
      if (result.preview && result.preview.columns) {
        state.columns = result.preview.columns;
        state.previewRows = result.preview.preview_rows || [];
        state.totalRows = result.preview.total_rows || 0;
        showUploadPreview({ columns: state.columns, preview_rows: state.previewRows, total_rows: state.totalRows, sheet_name: result.preview.sheet_name || "", warnings: [], errors: [] });
        var fi = document.getElementById("fileInfo");
        if (fi) { fi.hidden = false; document.getElementById("fileName").textContent = result.original_filename || "已上传文件";
          document.getElementById("fileMeta").textContent = "工作表：" + (result.preview.sheet_name || "Sheet1") + " · 字段：" + state.columns.length + " · 数据行：" + state.totalRows; }
      }
      if (result.step_data) {
        if (result.step_data.conditions) state.conditions = result.step_data.conditions;
        if (result.step_data.results) state.results = result.step_data.results;
        if (result.step_data.recommended_conditions) state.stepData.recommended_conditions = result.step_data.recommended_conditions;
      }
      goToStep(result.current_step || 1);
      var dn = document.getElementById("draftNotice"); if (dn) dn.hidden = true;
      hideLoading(); showToast("草稿已恢复，您可以继续编辑。", "success");
    } catch (e) { hideLoading(); showToast(e.message || "草稿恢复失败"); }
  }

  async function deleteDraft(draftId, token) {
    if (!confirm("确定要删除这个草稿吗？删除后不可恢复。")) return;
    showLoading("正在删除草稿...");
    try {
      var fd = new FormData(); fd.append("draft_id", draftId); fd.append("token", token);
      await wizardFetch("new_query_delete_draft", fd);
      window.location.reload();
    } catch (e) { hideLoading(); showToast(e.message || "删除草稿失败"); }
  }

  async function saveStep3() {
    if (!state.draftId) return;
    if (state.conditions.length === 0) throw new Error("请至少选择一个查询条件。");
    var fd = new FormData(); fd.append("draft_id", state.draftId); fd.append("token", state.token);
    fd.append("conditions", JSON.stringify(state.conditions));
    fd.append("results", JSON.stringify(state.results));
    await wizardFetch("new_query_step3", fd);
  }

  async function saveStep4() {
    if (!state.draftId) return;
    var ni = document.getElementById("wizardName");
    var name = ni ? ni.value.trim() : "";
    if (!name) throw new Error("请填写查询名称。");
    var fd = new FormData(); fd.append("draft_id", state.draftId); fd.append("token", state.token);
    fd.append("name", name);
    fd.append("login_notice", (document.getElementById("wizardLoginNotice") || {}).value || "");
    fd.append("result_notice", (document.getElementById("wizardResultNotice") || {}).value || "");
    fd.append("description", (document.getElementById("wizardDescription") || {}).value || "");
    fd.append("publish_now", (document.querySelector('input[name="publish_now"]:checked') || {}).value || "1");
    await wizardFetch("new_query_step4", fd);
  }

  // ===== 按钮事件 =====
  if (prevBtn) prevBtn.addEventListener("click", function() { if (state.currentStep > 1) goToStep(state.currentStep - 1); });
  if (nextBtn) nextBtn.addEventListener("click", async function() {
    if (state.currentStep < TOTAL_STEPS) {
      if (state.currentStep === 1 && !state.draftId) { showToast("请先上传 Excel 文件。"); return; }
      try {
        if (state.currentStep === 2) await saveStep2();
        if (state.currentStep === 3) await saveStep3();
        if (state.currentStep === 4) await saveStep4();
      } catch(e) { showToast(e.message); return; }
      if (state.currentStep === 4) initStep4();
      goToStep(state.currentStep + 1);
    }
  });
  if (publishBtn) publishBtn.addEventListener("click", function() {
    if (confirmDialog && typeof confirmDialog.showModal === "function") {
      var ct = document.getElementById("confirmPublishText");
      if (ct) ct.textContent = "将创建 1 个查询项目并导入 " + state.totalRows + " 条数据，是否继续？";
      confirmDialog.showModal();
    } else { if (confirm("将创建查询项目并导入 " + state.totalRows + " 条数据，是否继续？")) confirmPublish(); }
  });
  if (confirmPublishBtn) confirmPublishBtn.addEventListener("click", function() { if (confirmDialog) confirmDialog.close(); confirmPublish(); });
  if (cancelBtn) cancelBtn.addEventListener("click", function() {
    if (confirm("确定要取消吗？未发布的数据将作为草稿保留24小时。")) window.location.href = "admin.php?tab=projects";
  });
  var closeDialog = confirmDialog ? confirmDialog.querySelector("[data-close-dialog]") : null;
  if (closeDialog) closeDialog.addEventListener("click", function() { confirmDialog.close(); });

  // ===== 启动 =====
  initStep1();
  updateUI();
  if (state.columns.length > 0 && nextBtn) nextBtn.disabled = false;
})();
