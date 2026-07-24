(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const basePath = (window.ARTDON && window.ARTDON.basePath) || '';
  const route = (path = '') => `${basePath}/${String(path).replace(/^\//, '')}`.replace(/\/$/, path ? '' : '/');
  const storage = {
    get(key, fallback) {
      try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; }
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); }
  };

  const icons = {
    check: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>',
    plus: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>',
    minus: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14"/></svg>',
    close: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m6 6 12 12M18 6 6 18"/></svg>',
    cart: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 7H6"/></svg>'
  };

  function toast(message) {
    const region = $('[data-toast-region]');
    if (!region) return;
    const item = document.createElement('div');
    item.className = 'toast';
    item.innerHTML = `${icons.check}<span>${message}</span>`;
    region.appendChild(item);
    window.setTimeout(() => item.remove(), 3200);
  }

  // Sticky header
  const header = $('#siteHeader');
  if (header) {
    const onScroll = () => header.classList.toggle('is-sticky', window.scrollY > 34);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  // Mobile navigation and mega menus
  const mobileMenuButton = $('[data-mobile-menu]');
  const nav = $('[data-nav]');
  if (mobileMenuButton && nav) {
    mobileMenuButton.addEventListener('click', () => {
      const open = nav.classList.toggle('is-open');
      mobileMenuButton.setAttribute('aria-expanded', String(open));
      mobileMenuButton.innerHTML = open ? icons.close : '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16"/></svg>';
      document.body.classList.toggle('menu-open', open);
    });
    $$('[data-mega-trigger]').forEach(link => {
      link.addEventListener('click', event => {
        if (window.innerWidth <= 1120) {
          const parent = link.closest('.nav-item');
          if (parent && !parent.classList.contains('mega-open')) {
            event.preventDefault();
            $$('.nav-item.mega-open', nav).forEach(item => item !== parent && item.classList.remove('mega-open'));
            parent.classList.add('mega-open');
          }
        }
      });
    });
  }

  // Search overlay
  const searchPanel = $('[data-search-panel]');
  const openSearch = () => {
    if (!searchPanel) return;
    searchPanel.classList.add('is-open');
    searchPanel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    window.setTimeout(() => $('[data-global-search]', searchPanel)?.focus(), 100);
  };
  const closeSearch = () => {
    if (!searchPanel) return;
    searchPanel.classList.remove('is-open');
    searchPanel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  };
  $('[data-search-open]')?.addEventListener('click', openSearch);
  $('[data-search-close]')?.addEventListener('click', closeSearch);
  searchPanel?.addEventListener('click', event => { if (event.target === searchPanel) closeSearch(); });
  const searchData = [
    ['AL1010 Recessed Downlight', 'Product · Ready stock', 'product/AL1010'],
    ['AT2020 Track Spotlight', 'Product · Best seller', 'product/AT2020'],
    ['DALI-2 LED Driver', 'Product · High stock', 'product/DR7010'],
    ['IES Files', 'Technical resource', 'resources/ies'],
    ['Retail Lighting Solutions', 'Application solution', 'solutions/retail'],
    ['Quick RFQ', 'Procurement service', 'procurement/quick-rfq']
  ];
  const globalSearch = $('[data-global-search]');
  const suggestions = $('[data-search-suggestions]');
  function renderSuggestions(query = '') {
    if (!suggestions) return;
    const q = query.trim().toLowerCase();
    const matches = searchData.filter(row => !q || `${row[0]} ${row[1]}`.toLowerCase().includes(q)).slice(0, 6);
    suggestions.innerHTML = matches.map(row => `<a href="${route(row[2])}"><strong>${row[0]}</strong><small>${row[1]}</small></a>`).join('');
  }
  globalSearch?.addEventListener('input', () => renderSuggestions(globalSearch.value));
  renderSuggestions();

  // Homepage search tabs
  $$('[data-home-search-tab]').forEach(button => {
    button.addEventListener('click', () => {
      const key = button.dataset.homeSearchTab;
      $$('[data-home-search-tab]').forEach(b => b.classList.toggle('is-active', b === button));
      $$('[data-home-search-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.homeSearchPanel === key));
    });
  });

  // Product list filters
  $$('[data-product-filter]').forEach(button => {
    button.addEventListener('click', () => {
      const value = button.dataset.productFilter;
      $$('[data-product-filter]').forEach(b => b.classList.toggle('is-active', b === button));
      $$('[data-filter-grid] [data-product-card]').forEach(card => {
        const show = value === 'all' || card.dataset.category === value || card.dataset.stockGroup === value;
        card.hidden = !show;
      });
    });
  });
  const cardSearch = $('[data-card-search]');
  const filterGrid = $('[data-filter-grid]');
  const filterPanel = $('[data-filter-panel]');
  const filterInputs = $$('[data-list-filter]');
  const sortSelect = $('[data-product-sort]');
  const listingCards = filterGrid ? $$('[data-product-card]', filterGrid) : [];
  listingCards.forEach((card, index) => { card.dataset.originalIndex = String(index); });

  const selectedValues = group => filterInputs.filter(input => input.dataset.listFilter === group && input.checked).map(input => input.value);
  const matchPower = (raw, ranges) => {
    if (!ranges.length) return true;
    const values = (raw.match(/\d+(?:\.\d+)?/g) || []).map(Number);
    return ranges.some(range => values.some(value => {
      if (range === 'under-10') return value < 10;
      if (range === '10-20') return value >= 10 && value <= 20;
      if (range === '20-40') return value > 20 && value <= 40;
      return range === 'above-40' && value > 40;
    }));
  };
  const applyListingFilters = () => {
    if (!filterGrid) return;
    const needle = (cardSearch?.value || '').toLowerCase().trim();
    const availability = selectedValues('availability');
    const powers = selectedValues('power');
    const dimming = selectedValues('dimming');
    const optical = selectedValues('optical');
    listingCards.forEach(card => {
      const availabilityMatch = !availability.length || availability.some(value => value === 'ready' ? Number(card.dataset.stock || 0) > 0 : value === 'new' ? card.dataset.new === '1' : card.dataset.clearance === '1');
      const dimmingText = (card.dataset.dimming || '').replace(/[–—]/g, '-');
      const dimmingMatch = !dimming.length || dimming.some(value => {
        if (value === 'on-off') return dimmingText.includes('on / off') || dimmingText.includes('on/off');
        if (value === '0-10v') return dimmingText.includes('0-10v');
        if (value === 'phase-cut') return dimmingText.includes('phase-cut') || dimmingText.includes('phase cut');
        return value === 'dali-2' && (dimmingText.includes('dali-2') || dimmingText.includes('dali option'));
      });
      const opticalText = card.dataset.optical || '';
      const opticalMatch = !optical.length || optical.some(value => opticalText.includes(`${value}°`));
      const searchMatch = needle === '' || (card.dataset.search || '').includes(needle);
      card.hidden = !(availabilityMatch && matchPower(card.dataset.power || '', powers) && dimmingMatch && opticalMatch && searchMatch);
    });
    const countTarget = $('[data-visible-count]');
    if (countTarget) countTarget.textContent = String(listingCards.filter(card => !card.hidden).length);
  };
  const sortListing = () => {
    if (!filterGrid) return;
    const mode = sortSelect?.value || 'recommended';
    const cards = [...listingCards];
    cards.sort((a, b) => {
      if (mode === 'stock-desc') return Number(b.dataset.stock || 0) - Number(a.dataset.stock || 0);
      if (mode === 'price-asc') return Number(a.dataset.price || 0) - Number(b.dataset.price || 0);
      if (mode === 'newest') return Number(b.dataset.new || 0) - Number(a.dataset.new || 0) || Number(a.dataset.originalIndex) - Number(b.dataset.originalIndex);
      return Number(a.dataset.originalIndex) - Number(b.dataset.originalIndex);
    }).forEach(card => filterGrid.appendChild(card));
  };
  cardSearch?.addEventListener('input', applyListingFilters);
  filterInputs.forEach(input => input.addEventListener('change', applyListingFilters));
  sortSelect?.addEventListener('change', sortListing);
  $('[data-filter-reset]')?.addEventListener('click', () => {
    filterInputs.forEach(input => { input.checked = false; });
    if (cardSearch) cardSearch.value = '';
    if (sortSelect) sortSelect.value = 'recommended';
    sortListing();
    applyListingFilters();
  });
  $('[data-filter-open]')?.addEventListener('click', () => filterPanel?.classList.add('is-open'));
  $('[data-filter-close]')?.addEventListener('click', () => filterPanel?.classList.remove('is-open'));
  if (filterGrid && (cardSearch || filterInputs.length)) applyListingFilters();

  // Wishlist and compare
  const toggleStored = (key, sku, button) => {
    const values = storage.get(key, []);
    const index = values.indexOf(sku);
    if (index >= 0) values.splice(index, 1); else values.push(sku);
    storage.set(key, values);
    button.classList.toggle('is-active', index < 0);
    toast(index < 0 ? `${sku} saved` : `${sku} removed`);
  };
  $$('[data-wishlist]').forEach(button => {
    button.classList.toggle('is-active', storage.get('artdon_wishlist_v1', []).includes(button.dataset.wishlist));
    button.addEventListener('click', () => toggleStored('artdon_wishlist_v1', button.dataset.wishlist, button));
  });
  $$('[data-compare]').forEach(button => {
    button.classList.toggle('is-active', storage.get('artdon_compare_v1', []).includes(button.dataset.compare));
    button.addEventListener('click', () => toggleStored('artdon_compare_v1', button.dataset.compare, button));
  });

  // Cart
  const CART_KEY = 'artdon_cart_v1';
  const getCart = () => storage.get(CART_KEY, []);
  const saveCart = cart => { storage.set(CART_KEY, cart); updateCartCount(); };
  const cartKey = item => `${item.product_id || item.baseSku || item.sku}|${item.configured_model || item.sku}|${JSON.stringify(item.configuration || item.configuration_text || '')}`;
  const getOptionDefault = option => option.values.find(value => value.default) || option.values[0] || {};
  const selectionFromSchema = (schema, overrides = {}) => Object.fromEntries((schema.options || []).map(option => [option.code, overrides[option.code] ?? getOptionDefault(option).code]));
  const optionValue = (schema, code, selected) => (schema.options || []).find(option => option.code === code)?.values.find(value => value.code === selected[code]);
  const displayValue = (schema, code, selected) => optionValue(schema, code, selected)?.label || selected[code] || '';
  const ruleMatches = (rule, selected) => Object.entries(rule.when || {}).every(([code, value]) => selected[code] === value);
  const isDenied = (schema, selected, optionCode, valueCode) => (schema.rules || []).some(rule => rule.type === 'deny' && rule.option === optionCode && rule.value === valueCode && ruleMatches(rule, selected));
  const normalizeLegacyCartItem = item => ({
    product_id: item.product_id || item.baseSku || item.sku,
    product_name: item.product_name || item.name || '',
    model: item.model || item.baseSku || item.sku,
    series: item.series || '',
    image: item.image || 'downlight.svg',
    configured_model: item.configured_model || item.sku,
    configuration: item.configuration && typeof item.configuration === 'object' ? item.configuration : {},
    configuration_text: item.configuration_text || (typeof item.configuration === 'string' ? item.configuration : 'Standard ready-stock configuration'),
    quantity: Number(item.quantity || item.qty || 1),
    qty: Number(item.quantity || item.qty || 1),
    unit_price: item.unit_price ?? item.price ?? null,
    base_unit_price: item.base_unit_price ?? item.unit_price ?? item.price ?? null,
    price: item.unit_price ?? item.price ?? null,
    price_mode: item.price_mode || 'fixed',
    lead_time: item.lead_time || 'To be confirmed',
    schema: item.schema || item.configuration_schema || null,
    created_time: item.created_time || new Date().toISOString()
  });
  function buildConfiguredSnapshot(product, selected, quantity) {
    const schema = product.configuration_schema || {};
    const skuParts = (schema.sku_order || ['series']).map(part => {
      if (part === 'series') return product.series || product.sku;
      return optionValue(schema, part, selected)?.sku || selected[part];
    }).filter(Boolean);
    const priceMode = product.price_mode || schema.price_mode || 'fixed';
    let unitPrice = Number(product.base_unit_price ?? product.unit_price ?? product.price ?? 0);
    (schema.options || []).forEach(option => {
      const value = optionValue(schema, option.code, selected);
      unitPrice += Number(value?.price_delta || 0);
    });
    const configuration = Object.fromEntries((schema.options || []).map(option => [option.code, selected[option.code]]));
    const configurationText = (schema.options || []).map(option => `${option.label}: ${displayValue(schema, option.code, selected)}`).join(' · ');
    return {
      product_id: product.product_id || product.sku,
      product_name: product.product_name || product.name,
      name: product.product_name || product.name,
      model: product.model || product.sku,
      series: product.series,
      image: product.image,
      configured_model: skuParts.join('-'),
      sku: skuParts.join('-'),
      configuration,
      configuration_text: configurationText,
      quantity,
      qty: quantity,
      unit_price: priceMode === 'review' ? null : Number(unitPrice.toFixed(2)),
      base_unit_price: product.base_unit_price ?? product.unit_price ?? product.price ?? null,
      price: priceMode === 'review' ? null : Number(unitPrice.toFixed(2)),
      price_mode: priceMode,
      lead_time: product.lead_time || 'To be confirmed',
      schema,
      created_time: new Date().toISOString()
    };
  }
  function addToProjectCart(product) {
    const cart = getCart();
    const key = cartKey(product);
    const existing = cart.find(item => cartKey(item) === key);
    if (existing) {
      existing.quantity = Number(existing.quantity || existing.qty || 0) + Number(product.quantity || product.qty || 1);
      existing.qty = existing.quantity;
    } else {
      cart.push({ ...product, quantity: Number(product.quantity || product.qty || 1), qty: Number(product.quantity || product.qty || 1) });
    }
    saveCart(cart);
    toast(`${product.configured_model || product.sku} added to Project Cart`);
    renderCart();
  }
  const addToCart = product => addToProjectCart(normalizeLegacyCartItem(product));
  function updateCartCount() {
    const cart = getCart().map(normalizeLegacyCartItem);
    const productCount = cart.length;
    const qtyCount = cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    $$('[data-cart-count]').forEach(el => {
      el.textContent = String(productCount);
      el.title = `${productCount} Products · ${qtyCount} pcs`;
    });
    $$('[data-cart-summary]').forEach(el => { el.textContent = `${productCount} Products · ${qtyCount} pcs`; });
  }
  let quickConfigProduct = null;
  let quickConfigEditIndex = null;
  let quickConfigSelection = {};
  let quickConfigSnapshot = null;
  const closeQuickConfig = () => {
    const panel = $('[data-quick-config]');
    if (!panel) return;
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('quick-config-open');
    quickConfigEditIndex = null;
  };
  function ensureQuickConfigPanel() {
    let panel = $('[data-quick-config]');
    if (panel) return panel;
    panel = document.createElement('div');
    panel.className = 'quick-config';
    panel.dataset.quickConfig = '';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML = '<div class="quick-config-backdrop" data-quick-config-close></div><aside class="quick-config-panel" role="dialog" aria-modal="true" aria-label="Quick Configuration"><button class="quick-config-close" type="button" data-quick-config-close aria-label="Close">'+icons.close+'</button><div data-quick-config-content></div></aside>';
    document.body.appendChild(panel);
    $$('[data-quick-config-close]', panel).forEach(button => button.addEventListener('click', closeQuickConfig));
    return panel;
  }
  function renderQuickConfig() {
    const panel = ensureQuickConfigPanel();
    const content = $('[data-quick-config-content]', panel);
    if (!quickConfigProduct || !content) return;
    const schema = quickConfigProduct.configuration_schema || {};
    let selected = { ...quickConfigSelection };
    (schema.options || []).forEach(option => {
      if (isDenied(schema, selected, option.code, selected[option.code])) {
        const replacement = option.values.find(value => !isDenied(schema, selected, option.code, value.code));
        if (replacement) selected[option.code] = replacement.code;
      }
    });
    quickConfigSelection = selected;
    const qty = Math.max(Number(quickConfigProduct.moq || 1), Number($('[data-quick-config-qty]', panel)?.value || quickConfigProduct.quantity || quickConfigProduct.qty || quickConfigProduct.moq || 1));
    quickConfigSnapshot = buildConfiguredSnapshot(quickConfigProduct, selected, qty);
    const notices = (schema.rules || []).filter(rule => rule.type === 'deny' && ruleMatches(rule, selected)).map(rule => rule.message).filter(Boolean);
    const optionHtml = (schema.options || []).map(option => `<div class="quick-config-field"><span>${escapeHtml(option.label)}</span><div class="quick-config-options">${option.values.map(value => {
      const disabled = isDenied(schema, selected, option.code, value.code);
      const active = selected[option.code] === value.code;
      return `<button type="button" data-quick-option="${escapeHtml(option.code)}" data-value="${escapeHtml(value.code)}" class="${active ? 'is-active' : ''}" ${disabled ? 'disabled' : ''}>${escapeHtml(value.label)}</button>`;
    }).join('')}</div></div>`).join('');
    const priceHtml = quickConfigSnapshot.price_mode === 'review' || quickConfigSnapshot.unit_price === null ? 'Price confirmed after review' : `${cartMoney(quickConfigSnapshot.unit_price)} / pcs`;
    content.innerHTML = `<header class="quick-config-head"><img src="${route(`assets/img/${quickConfigProduct.image || 'downlight.svg'}`)}" alt=""><div><span>Quick Configuration</span><h2>${escapeHtml(quickConfigProduct.product_name || quickConfigProduct.name || '')}</h2><p>${escapeHtml(quickConfigProduct.model || quickConfigProduct.sku || '')} · ${escapeHtml(quickConfigProduct.series || '')} Series</p></div></header><div class="quick-config-scroll">${optionHtml}<div class="quick-config-field"><span>Quantity</span><div class="quick-qty"><button type="button" data-quick-qty-minus>${icons.minus}</button><input type="number" min="${Number(quickConfigProduct.moq || 1)}" value="${qty}" data-quick-config-qty><button type="button" data-quick-qty-plus>${icons.plus}</button></div><small>MOQ: ${Number(quickConfigProduct.moq || 1)} pcs</small></div>${notices.length ? `<p class="quick-config-rule-note">${escapeHtml([...new Set(notices)].join(' '))}</p>` : ''}</div><footer class="quick-config-foot"><div><span>Your Selection</span><strong data-quick-config-model>${escapeHtml(quickConfigSnapshot.configured_model)}</strong></div><div><span>Estimated Price</span><strong>${escapeHtml(priceHtml)}</strong></div><button type="button" class="button button-dark button-large button-block" data-quick-config-submit>${quickConfigEditIndex === null ? 'ADD TO PROJECT CART' : 'SAVE CHANGES'}</button></footer>`;
    $$('[data-quick-option]', content).forEach(button => button.addEventListener('click', () => {
      if (button.disabled) return;
      quickConfigSelection[button.dataset.quickOption] = button.dataset.value;
      renderQuickConfig();
    }));
    $('[data-quick-qty-minus]', content)?.addEventListener('click', () => {
      quickConfigProduct.quantity = Math.max(Number(quickConfigProduct.moq || 1), qty - 1);
      renderQuickConfig();
    });
    $('[data-quick-qty-plus]', content)?.addEventListener('click', () => {
      quickConfigProduct.quantity = qty + 1;
      renderQuickConfig();
    });
    $('[data-quick-config-qty]', content)?.addEventListener('change', event => {
      quickConfigProduct.quantity = Math.max(Number(quickConfigProduct.moq || 1), Number(event.currentTarget.value || quickConfigProduct.moq || 1));
      renderQuickConfig();
    });
    $('[data-quick-config-submit]', content)?.addEventListener('click', () => {
      if (!quickConfigSnapshot) return;
      if (quickConfigEditIndex === null) addToProjectCart(quickConfigSnapshot);
      else {
        const cart = getCart();
        cart[quickConfigEditIndex] = quickConfigSnapshot;
        saveCart(cart);
        renderCart();
        toast(`${quickConfigSnapshot.configured_model} updated`);
      }
      closeQuickConfig();
    });
  }
  function openQuickConfig(product, editIndex = null) {
    quickConfigProduct = product;
    quickConfigEditIndex = editIndex;
    quickConfigSelection = selectionFromSchema(product.configuration_schema || {}, product.configuration || {});
    quickConfigProduct.quantity = Number(product.quantity || product.qty || product.moq || 1);
    const panel = ensureQuickConfigPanel();
    renderQuickConfig();
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('quick-config-open');
  }
  $$('[data-quick-config-open]').forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      try { openQuickConfig(JSON.parse(button.dataset.product || '{}')); } catch { toast('Unable to configure this product'); }
    });
  });
  $$('[data-add-cart]').forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      try {
        const product = JSON.parse(button.dataset.product || '{}');
        if (product.configuration_schema) openQuickConfig(product);
        else addToCart(product);
      } catch {
        toast('Unable to configure this product');
      }
    });
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeQuickConfig();
  });

  // Product gallery thumbnails
  const thumbnailWrap = $('[data-product-thumbnails]');
  const mainProductImage = $('[data-main-product-image]');
  if (thumbnailWrap && mainProductImage) {
    $$('button', thumbnailWrap).forEach(button => button.addEventListener('click', () => {
      const image = $('img', button);
      if (!image) return;
      mainProductImage.src = image.src;
      $$('button', thumbnailWrap).forEach(item => item.classList.toggle('is-active', item === button));
    }));
  }

  // Product configurator
  const configurator = $('[data-product-configurator]');
  let configuredSelection = null;
  if (configurator) {
    const baseSku = configurator.dataset.baseSku || 'PRODUCT';
    const basePrice = Number(configurator.dataset.basePrice || 0);
    const selects = $$('[data-config-option]', configurator);
    const skuTarget = $('[data-config-sku]', configurator);
    const priceTarget = $('[data-config-price]', configurator);
    const qtyInput = $('[data-config-qty]', configurator);
    const ruleNote = $('[data-config-rule-note]', configurator);
    const selectByName = name => selects.find(select => select.name === name);
    const disableValue = (select, value) => {
      if (!select) return;
      Array.from(select.options).forEach(option => { if (option.value === value) option.disabled = true; });
    };
    const applyCombinationRules = () => {
      selects.forEach(select => Array.from(select.options).forEach(option => { option.disabled = false; }));
      const notices = [];
      const powerSelect = selectByName('power');
      const beamSelect = selectByName('beam_angle');
      const driverSelect = selectByName('driver');
      const dimmingSelect = selectByName('dimming');
      const accessorySelect = selectByName('accessory');

      if (powerSelect?.value === '20W') {
        disableValue(beamSelect, '15°');
        if (beamSelect?.value === '15°') { beamSelect.value = '24°'; notices.push('20W starts from a 24° beam.'); }
      }
      if (dimmingSelect?.value === 'DALI-2') {
        disableValue(driverSelect, 'Lifud');
        if (driverSelect?.value === 'Lifud') { driverSelect.value = 'Tridonic'; notices.push('DALI-2 requires a compatible driver.'); }
      }
      if (beamSelect?.value === '60°') {
        disableValue(accessorySelect, 'Honeycomb');
        if (accessorySelect?.value === 'Honeycomb') { accessorySelect.value = 'None'; notices.push('Honeycomb is unavailable with the 60° optic.'); }
      }
      if (ruleNote) {
        ruleNote.hidden = notices.length === 0;
        ruleNote.textContent = notices.join(' ');
      }
    };
    const abbreviate = value => value.toUpperCase().replace(/\s*\/\s*/g, '').replace(/[^A-Z0-9°]/g, '').replace('RECESSED', 'REC').replace('SURFACE', 'SUR').replace('TRIDONIC', 'TRI').replace('PHILIPS', 'PHI').replace('LIFUD', 'LIF').replace('ONOFF', 'ON').replace('HONEYCOMB', 'HC').replace('ANTIGLARERING', 'AGR').slice(0, 8);
    const updateConfiguration = () => {
      applyCombinationRules();
      const values = Object.fromEntries(selects.map(select => [select.name, select.value]));
      const skuParts = [baseSku, values.power, values.cct, values.beam_angle, values.finish, values.dimming, values.accessory].map(abbreviate);
      let price = basePrice;
      const power = parseFloat(values.power || '0');
      if (power > 10) price += (power - 10) * 1.15;
      if (values.cri === 'Ra95') price += 4;
      if (values.driver === 'Tridonic') price += 5;
      if (values.dimming === '0–10V') price += 4;
      if (values.dimming === 'DALI-2') price += 12;
      if (values.accessory === 'Honeycomb') price += 5;
      if (values.accessory === 'Anti-glare ring') price += 3;
      const configuration = selects.map(select => `${select.closest('label')?.querySelector('span')?.textContent || select.name}: ${select.value}`).join(' · ');
      configuredSelection = {
        sku: baseSku,
        configuredSku: skuParts.join('-'),
        configuration,
        price: Number(price.toFixed(2)),
        qty: Number(qtyInput?.value || 1)
      };
      if (skuTarget) skuTarget.textContent = configuredSelection.configuredSku;
      if (priceTarget) priceTarget.textContent = configuredSelection.price.toFixed(2);
    };
    selects.forEach(select => select.addEventListener('change', updateConfiguration));
    qtyInput?.addEventListener('input', updateConfiguration);
    $('[data-qty-minus]', configurator)?.addEventListener('click', () => { if (qtyInput) { qtyInput.value = String(Math.max(1, Number(qtyInput.value || 1) - 1)); updateConfiguration(); } });
    $('[data-qty-plus]', configurator)?.addEventListener('click', () => { if (qtyInput) { qtyInput.value = String(Number(qtyInput.value || 1) + 1); updateConfiguration(); } });
    $('[data-reset-config]', configurator)?.addEventListener('click', () => { selects.forEach(select => select.selectedIndex = 0); if (qtyInput) qtyInput.value = qtyInput.min || '1'; updateConfiguration(); });
    $('[data-add-configured-cart]', configurator)?.addEventListener('click', event => {
      const button = event.currentTarget;
      let base = {};
      try { base = JSON.parse(button.dataset.product || '{}'); } catch {}
      updateConfiguration();
      addToCart({ ...base, sku: configuredSelection.configuredSku, baseSku, configuration: configuredSelection.configuration, price: configuredSelection.price, qty: configuredSelection.qty });
    });
    updateConfiguration();
  }

  function cartMoney(value) { return `USD ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`; }
  function renderCart() {
    const container = $('[data-cart-items]');
    if (!container) return;
    const cart = getCart();
    const checkoutButton = $('[data-cart-checkout]');
    if (!cart.length) {
      container.innerHTML = `<div class="empty-cart"><div>${icons.cart}</div><h2>Your Project Cart is empty</h2><p>Add configured ready-stock or product items, then submit one consolidated project request.</p><a class="button button-dark" href="${route('ready-stock')}">Browse ready stock</a></div>`;
      if (checkoutButton) checkoutButton.disabled = true;
    } else {
      container.innerHTML = `<div class="project-cart-table"><div class="project-cart-head"><span>Product</span><span>Configuration</span><span>Quantity</span><span>Price</span><span>Lead Time</span><span>Action</span></div>${cart.map((rawItem, index) => {
        const item = normalizeLegacyCartItem(rawItem);
        const price = item.price_mode === 'review' || item.unit_price === null ? 'Review' : cartMoney(item.unit_price);
        const configHtml = item.configuration && Object.keys(item.configuration).length && item.schema ? (item.schema.options || []).map(option => `<span>${escapeHtml(displayValue(item.schema, option.code, item.configuration))}</span>`).join('') : `<span>${escapeHtml(item.configuration_text)}</span>`;
        return `<div class="project-cart-row" data-cart-row="${index}"><div class="project-cart-product"><img src="${route(`assets/img/${item.image || 'downlight.svg'}`)}" alt=""><span><strong>${escapeHtml(item.model || item.sku)}</strong><small>${escapeHtml(item.product_name || '')}</small><small>${escapeHtml(item.configured_model || item.sku)}</small></span></div><div class="project-cart-config">${configHtml}</div><div class="cart-row-qty"><button type="button" data-cart-minus="${index}">${icons.minus}</button><input aria-label="Quantity" type="number" min="1" value="${Number(item.quantity || 1)}" data-cart-qty="${index}"><button type="button" data-cart-plus="${index}">${icons.plus}</button></div><div class="cart-row-price">${escapeHtml(price)}</div><div class="project-cart-lead">${escapeHtml(item.lead_time)}</div><div class="project-cart-actions"><button type="button" data-cart-edit="${index}">Edit</button><button type="button" data-cart-remove="${index}">Delete</button></div></div>`;
      }).join('')}</div>`;
      if (checkoutButton) checkoutButton.disabled = false;
    }
    const normalizedCart = cart.map(normalizeLegacyCartItem);
    const itemCount = normalizedCart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const subtotal = normalizedCart.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.unit_price || 0), 0);
    $('[data-cart-product-total]') && ($('[data-cart-product-total]').textContent = String(normalizedCart.length));
    $('[data-cart-item-total]') && ($('[data-cart-item-total]').textContent = String(itemCount));
    $('[data-cart-subtotal]') && ($('[data-cart-subtotal]').textContent = cartMoney(subtotal));
    $('[data-cart-total]') && ($('[data-cart-total]').textContent = cartMoney(subtotal));
    $('[data-cart-json]') && ($('[data-cart-json]').value = JSON.stringify(normalizedCart));
    $$('[data-cart-minus]').forEach(button => button.addEventListener('click', () => changeCartQty(Number(button.dataset.cartMinus), -1)));
    $$('[data-cart-plus]').forEach(button => button.addEventListener('click', () => changeCartQty(Number(button.dataset.cartPlus), 1)));
    $$('[data-cart-qty]').forEach(input => input.addEventListener('change', () => setCartQty(Number(input.dataset.cartQty), Number(input.value))));
    $$('[data-cart-remove]').forEach(button => button.addEventListener('click', () => removeCartItem(Number(button.dataset.cartRemove))));
    $$('[data-cart-edit]').forEach(button => button.addEventListener('click', () => {
      const index = Number(button.dataset.cartEdit);
      const item = normalizeLegacyCartItem(getCart()[index] || {});
      if (!item.schema) return toast('This legacy item cannot be edited');
      openQuickConfig({
        ...item,
        product_id: item.product_id,
        product_name: item.product_name,
        name: item.product_name,
        sku: item.model,
        unit_price: item.price_mode === 'review' ? null : item.unit_price,
        base_unit_price: item.base_unit_price,
        price: item.price_mode === 'review' ? null : item.unit_price,
        configuration_schema: item.schema,
        moq: 1
      }, index);
    }));
  }
  function setCartQty(index, qty) {
    const cart = getCart();
    if (!cart[index]) return;
    const nextQty = Math.max(1, Number.isFinite(qty) ? qty : 1);
    cart[index].qty = nextQty;
    cart[index].quantity = nextQty;
    saveCart(cart);
    renderCart();
  }
  function changeCartQty(index, delta) {
    const cart = getCart();
    if (!cart[index]) return;
    setCartQty(index, Number(cart[index].quantity || cart[index].qty || 1) + delta);
  }
  function removeCartItem(index) {
    const cart = getCart();
    const removed = cart.splice(index, 1)[0];
    saveCart(cart);
    renderCart();
    if (removed) toast(`${removed.configured_model || removed.sku} removed from Project Cart`);
  }
  $('[data-cart-checkout]')?.addEventListener('click', () => {
    const formWrap = $('[data-checkout-form]');
    if (formWrap) { formWrap.hidden = false; formWrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  });
  $('[data-checkout-back]')?.addEventListener('click', () => {
    const formWrap = $('[data-checkout-form]');
    if (formWrap) formWrap.hidden = true;
    $('[data-cart-items]')?.scrollIntoView({ behavior: 'smooth' });
  });
  updateCartCount();
  renderCart();

  // Tabs
  $$('[data-tabs]').forEach(tabset => {
    const buttons = $$('[data-tab]', tabset);
    buttons.forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.tab;
      buttons.forEach(b => b.classList.toggle('is-active', b === button));
      const section = tabset.parentElement;
      $$('[data-tab-panel]', section || document).forEach(panel => panel.classList.toggle('is-active', panel.dataset.tabPanel === key));
    }));
  });

  // RFQ modal
  const rfqModal = $('[data-rfq-modal]');
  const rfqSelection = $('[data-rfq-selection]');
  const openRfq = selection => {
    if (!rfqModal) return;
    if (rfqSelection) rfqSelection.value = typeof selection === 'string' ? selection : JSON.stringify(selection || {});
    rfqModal.classList.add('is-open');
    rfqModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  };
  const closeRfq = () => {
    if (!rfqModal) return;
    rfqModal.classList.remove('is-open');
    rfqModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
  };
  $$('[data-rfq-open]').forEach(button => button.addEventListener('click', () => {
    if (button.hasAttribute('data-configured-rfq') && configuredSelection) openRfq(configuredSelection);
    else {
      try { openRfq(JSON.parse(button.dataset.product || '{}')); } catch { openRfq(button.dataset.product || ''); }
    }
  }));
  $('[data-modal-close]')?.addEventListener('click', closeRfq);
  rfqModal?.addEventListener('click', event => { if (event.target === rfqModal) closeRfq(); });

  // API forms
  $$('[data-api-form]').forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const status = $('[data-form-status]', form);
      const submit = $('button[type="submit"]', form);
      if (submit) { submit.disabled = true; submit.dataset.originalText = submit.textContent; submit.textContent = 'Submitting…'; }
      if (status) { status.className = 'form-status'; status.textContent = ''; }
      try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Submission failed.');
        if (status) { status.className = 'form-status is-success'; status.textContent = `${result.message} Reference: ${result.reference}`; }
        toast(result.duplicate ? `Request ${result.reference} was already recorded` : `Request ${result.reference} submitted`);
        const tokenInput = $('input[name="submission_token"]', form);
        if (tokenInput && result.next_submission_token) tokenInput.value = result.next_submission_token;
        if (form.hasAttribute('data-cart-order-form')) { saveCart([]); renderCart(); }
        if (form.closest('[data-rfq-modal]')) window.setTimeout(closeRfq, 1200);
        const preserved = $$('input[type="hidden"]', form).map(input => [input.name, input.value]);
        form.reset();
        preserved.forEach(([name, value]) => { const input = form.querySelector(`input[name="${CSS.escape(name)}"]`); if (input) input.value = value; });
      } catch (error) {
        if (status) { status.className = 'form-status is-error'; status.textContent = error.message || 'Unable to submit. Please try again.'; }
      } finally {
        if (submit) { submit.disabled = false; submit.textContent = submit.dataset.originalText || 'Submit'; }
      }
    });
  });

  // Resource search and demo actions
  $('[data-resource-search]')?.addEventListener('input', event => {
    const needle = event.currentTarget.value.toLowerCase().trim();
    $$('[data-resource-table] [data-search]').forEach(row => row.hidden = needle && !(row.dataset.search || '').includes(needle));
  });
  $$('[data-demo-download]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); toast('Demo file link — connect this to the media library'); }));
  $$('[data-demo-save]').forEach(button => button.addEventListener('click', () => toast('Settings saved in demo mode')));

  // File drop labels
  $$('.file-drop input[type="file"]').forEach(input => input.addEventListener('change', () => {
    const label = input.closest('.file-drop');
    const strong = $('strong', label);
    if (strong && input.files?.length) strong.textContent = `${input.files.length} file${input.files.length > 1 ? 's' : ''} selected`;
  }));

  // AI tools
  $('[data-calc-lighting]')?.addEventListener('click', () => {
    const length = Number($('[data-calc-length]')?.value || 0);
    const width = Number($('[data-calc-width]')?.value || 0);
    const lux = Number($('[data-calc-lux]')?.value || 0);
    const lumens = Number($('[data-calc-lumens]')?.value || 1);
    const uf = Number($('[data-calc-uf]')?.value || 1);
    const mf = Number($('[data-calc-mf]')?.value || 1);
    const fixtures = Math.max(1, Math.ceil((length * width * lux) / Math.max(1, lumens * uf * mf)));
    const target = $('[data-lighting-result] strong');
    if (target) target.textContent = `${fixtures} fixtures`;
  });
  $('[data-calc-beam]')?.addEventListener('click', () => {
    const height = Number($('[data-beam-height]')?.value || 0);
    const diameter = Number($('[data-beam-diameter]')?.value || 0);
    const angle = (2 * Math.atan(diameter / Math.max(.01, 2 * height)) * 180 / Math.PI);
    const standards = [10, 15, 18, 24, 36, 45, 60];
    const nearest = standards.reduce((a, b) => Math.abs(b - angle) < Math.abs(a - angle) ? b : a);
    const target = $('[data-beam-result] strong');
    if (target) target.textContent = `${nearest}°`;
  });
  $('[data-calc-driver]')?.addEventListener('click', () => {
    const watts = Number($('[data-driver-watts]')?.value || 0);
    const qty = Number($('[data-driver-qty]')?.value || 1);
    const factor = Number($('[data-driver-factor]')?.value || 1.2);
    const capacity = Math.ceil(watts * qty * factor);
    const target = $('[data-driver-result] strong');
    if (target) target.textContent = `${capacity}W minimum`;
  });
  $('[data-product-finder]')?.addEventListener('submit', event => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form).entries());
    const result = $('[data-finder-results]');
    if (!result) return;
    const recommendation = values.installation === 'Track mounted' ? 'AT2020 Track Spotlight' : values.installation === 'Pendant' ? 'LN4010 Architectural Linear' : 'AL1010 Recessed Downlight';
    result.innerHTML = `<span class="eyebrow">Recommended starting point</span><h3>${recommendation}</h3><p>${escapeHtml(values.application)} · ${escapeHtml(values.purpose)} · ${escapeHtml(values.dimming)} · ${escapeHtml(values.height)}m mounting height</p><div class="button-row"><a class="button button-dark" href="${route(`product/${recommendation.split(' ')[0]}`)}">Configure product</a><button class="button button-outline" type="button" data-rfq-open-inline>Add result to RFQ</button></div>`;
    $('[data-rfq-open-inline]', result)?.addEventListener('click', () => openRfq({ recommendation, requirement: values }));
  });
  $$('[data-demo-chat]').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault();
    const textarea = $('textarea', form);
    if (!textarea?.value.trim()) return;
    const chat = form.closest('.ai-chat');
    const message = document.createElement('div');
    message.className = 'chat-message assistant';
    message.innerHTML = `<div>${icons.check}</div><p>Demo recommendation recorded. Connect the live AI service and product rule engine to return compatible models, files and procurement actions.</p>`;
    chat?.insertBefore(message, form);
    textarea.value = '';
  }));
  $$('.chat-suggestions button').forEach(button => button.addEventListener('click', () => {
    const textarea = $('.ai-chat textarea');
    if (textarea) { textarea.value = button.textContent || ''; textarea.focus(); }
  }));

  // Escape key
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeSearch();
      closeRfq();
      filterPanel?.classList.remove('is-open');
      if (nav?.classList.contains('is-open')) mobileMenuButton?.click();
    }
  });

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
  }
})();
