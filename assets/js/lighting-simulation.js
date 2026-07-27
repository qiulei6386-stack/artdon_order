(() => {
  'use strict';

  const root = document.querySelector('[data-lighting-simulation]');
  if (!root) return;

  const $ = (selector, parent = root) => parent.querySelector(selector);
  const basePath = window.ARTDON?.basePath || '';
  const route = path => `${basePath}/${String(path).replace(/^\//, '')}`;
  const csrf = window.ARTDON?.csrf || '';
  const form = $('[data-simulation-form]');
  const productSelect = $('[data-simulation-product]');
  const profileCard = $('[data-simulation-profile]');
  const status = $('[data-simulation-status]');
  const submitButton = $('[data-simulation-submit]');
  const empty = $('[data-simulation-empty]');
  const resultsPanel = $('[data-simulation-results]');
  const canvas = $('[data-simulation-heatmap]');
  const tooltip = $('[data-simulation-tooltip]');
  const roomType = $('[data-simulation-room-type]');
  const targetInput = $('[data-simulation-target]');
  const targetNote = $('[data-simulation-target-note]');
  const saveButton = $('[data-simulation-save]');
  const addCartButton = $('[data-simulation-add-cart]');
  const reportLink = $('[data-simulation-report]');
  const projectNameInput = $('[data-simulation-project-name]');
  const initialSku = root.dataset.initialProduct || '';

  const state = {
    products: [],
    profiles: new Map(),
    activeProfile: null,
    latest: null,
    savedProject: null,
    urlConfiguration: {},
    heatmapGeometry: null
  };

  const roomGuidance = {
    retail: [400, 'Retail starting point: 300–500 lux. Confirm the applicable project standard.'],
    office: [500, 'Office task areas commonly start around 500 lux. Review glare and screen use.'],
    hotel: [300, 'Hotel public spaces commonly start around 200–400 lux with layered accents.'],
    restaurant: [200, 'Dining spaces commonly use 150–300 lux with scene control.'],
    gallery: [300, 'Gallery targets vary by exhibit; verify conservation and vertical illuminance.'],
    museum: [300, 'Museum limits depend on object sensitivity; verify conservation requirements.'],
    residential: [200, 'Residential ambient lighting commonly starts around 100–300 lux.'],
    warehouse: [250, 'Warehouse targets depend on task and aisle conditions; check vertical planes.']
  };

  try {
    const query = new URLSearchParams(window.location.search);
    const supplied = query.get('configuration');
    if (supplied) {
      const parsed = JSON.parse(supplied);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) state.urlConfiguration = parsed;
    }
  } catch {}

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
  })[character]);
  const hasUrlConfiguration = () => Object.keys(state.urlConfiguration).length > 0;
  const profileMatchesUrlConfiguration = profile => {
    if (!hasUrlConfiguration()) return true;
    return Object.entries(profile?.configuration_match || {}).every(([key, expected]) =>
      Object.prototype.hasOwnProperty.call(state.urlConfiguration, key)
        && String(state.urlConfiguration[key]) === String(expected)
    );
  };
  const idempotencyKey = () => window.crypto?.randomUUID?.()
    || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const number = (value, digits = 0) => Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits
  });

  async function jsonRequest(path, options = {}) {
    const response = await fetch(route(path), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', ...(options.headers || {}) },
      ...options
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.success) {
      const error = new Error(payload.message || payload.error?.message || 'The request could not be completed.');
      error.status = response.status;
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function setStatus(message = '', type = '') {
    status.textContent = message;
    status.className = `simulation-form-status${type ? ` is-${type}` : ''}`;
  }

  function selectedProfile() {
    return state.profiles.get(productSelect.value) || null;
  }

  function renderProfile() {
    const profile = selectedProfile();
    state.activeProfile = profile;
    if (!profile) {
      profileCard.innerHTML = '<span>No simulation-ready profile selected.</span>';
      submitButton.disabled = true;
      return;
    }
    const values = [
      profile.power_w ? `${number(profile.power_w)}W` : null,
      profile.lumens ? `${number(profile.lumens)} lm` : null,
      profile.beam_angle_deg ? `${number(profile.beam_angle_deg, 1)}° beam` : null,
      profile.ies?.standard || null
    ].filter(Boolean).join(' · ');
    const sourceStatus = profile.manufacturer_validated
      ? '<small class="profile-valid">Manufacturer/laboratory provenance independently verified.</small>'
      : profile.data_status === 'synthetic_preliminary_demo'
        ? '<small class="profile-warning">Preliminary synthetic demo profile — not manufacturer validated.</small>'
        : '<small class="profile-warning">Preliminary library profile — manufacturer/laboratory provenance not verified.</small>';
    profileCard.innerHTML = `
      <strong>${escapeHtml(profile.product.sku)} · ${escapeHtml(profile.configured_model)}</strong>
      <span>${escapeHtml(values)}</span>
      <small>${escapeHtml(profile.ies?.original_name || '')}</small>
      ${sourceStatus}`;
    submitButton.disabled = false;
  }

  async function loadProducts() {
    try {
      const payload = await jsonRequest('api/lighting-products.php');
      state.products = payload.products || [];
      state.profiles.clear();
      productSelect.innerHTML = '';
      const requestedSku = initialSku.trim().toLowerCase();
      let requestedProductFound = false;
      state.products.forEach(product => {
        if (requestedSku && product.sku.toLowerCase() !== requestedSku) return;
        requestedProductFound = true;
        const group = document.createElement('optgroup');
        group.label = `${product.sku} · ${product.name}`;
        (product.profiles || []).forEach(profile => {
          if (!profileMatchesUrlConfiguration(profile)) return;
          profile.product = product;
          state.profiles.set(profile.id, profile);
          const option = document.createElement('option');
          option.value = profile.id;
          option.textContent = [
            product.sku,
            profile.power_w ? `${number(profile.power_w)}W` : '',
            profile.beam_angle_deg ? `${number(profile.beam_angle_deg, 1)}°` : ''
          ].filter(Boolean).join(' · ');
          group.appendChild(option);
        });
        if (group.children.length) productSelect.appendChild(group);
      });
      if (!state.profiles.size) {
        const unavailableMessage = requestedSku && !requestedProductFound
          ? `Lighting Simulation is not yet available for ${initialSku}. No other product has been substituted.`
          : requestedSku && hasUrlConfiguration()
            ? `This ${initialSku} configuration has no matching IES profile. Choose a supported power and beam in Configure & Order.`
            : requestedSku
              ? `Lighting Simulation is not yet available for ${initialSku}. No other product has been substituted.`
              : 'No simulation-ready products are available.';
        productSelect.innerHTML = '<option value="">No matching IES profile</option>';
        submitButton.disabled = true;
        setStatus(unavailableMessage, 'error');
      } else if (requestedSku && hasUrlConfiguration()) {
        setStatus(`Matched the IES profile to the configured ${initialSku} selection. Your product options will be preserved.`, 'success');
      } else if (requestedSku) {
        setStatus(`Showing only IES profiles for ${initialSku}.`, 'success');
      }
      renderProfile();
    } catch (error) {
      productSelect.innerHTML = '<option value="">Profiles unavailable</option>';
      submitButton.disabled = true;
      setStatus(error.message, 'error');
    }
  }

  function updateRoomGuidance(changeValue = true) {
    const [suggested, note] = roomGuidance[roomType.value] || roomGuidance.retail;
    if (changeValue) targetInput.value = String(suggested);
    targetNote.textContent = note;
  }

  function formPayload() {
    const data = new FormData(form);
    const length = Number(data.get('length_m'));
    const width = Number(data.get('width_m'));
    const gridNx = Math.max(20, Math.min(50, Math.ceil(length / .35)));
    const gridNy = Math.max(20, Math.min(50, Math.ceil(width / .35)));
    const mode = data.get('mode') === 'one_light' ? 'single' : 'auto_layout';
    return {
      profile_id: String(data.get('ies_profile_id') || ''),
      product_sku: initialSku.trim() || selectedProfile()?.product?.sku || '',
      project_name: projectNameInput.value.trim(),
      configuration: hasUrlConfiguration()
        ? { ...state.urlConfiguration }
        : { ...(selectedProfile()?.configuration_match || {}) },
      mode,
      room: {
        type: String(data.get('room_type')),
        length_m: length,
        width_m: width,
        height_m: Number(data.get('height_m')),
        installation_height_m: Number(data.get('installation_height_m')),
        calculation_plane_m: 0,
        mounting_type: String(data.get('mounting_type')),
        target_lux: Number(data.get('target_lux'))
      },
      maintenance_factor: .8,
      options: { grid_nx: Math.min(36, gridNx), grid_ny: Math.min(36, gridNy), max_fixtures: 96 }
    };
  }

  function colorForRatio(ratio) {
    const stops = [
      [0, [18, 37, 126]],
      [.5, [12, 185, 202]],
      [1, [126, 214, 71]],
      [1.5, [255, 199, 36]],
      [2.2, [237, 52, 49]]
    ];
    const value = Math.max(0, Math.min(2.2, ratio));
    for (let index = 1; index < stops.length; index++) {
      if (value <= stops[index][0]) {
        const [x0, c0] = stops[index - 1];
        const [x1, c1] = stops[index];
        const t = (value - x0) / (x1 - x0);
        return c0.map((component, channel) => Math.round(component + (c1[channel] - component) * t));
      }
    }
    return stops.at(-1)[1];
  }

  function drawHeatmap(result) {
    const heatmap = result.heatmap || {};
    const nx = Number(heatmap.nx || 0);
    const ny = Number(heatmap.ny || 0);
    const values = heatmap.values_lux || [];
    if (!canvas || !nx || !ny || values.length !== nx * ny) return;

    const context = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    const margin = { left: 62, right: 28, top: 25, bottom: 55 };
    const roomLength = Number(result.room?.length_m || 1);
    const roomWidth = Number(result.room?.width_m || 1);
    const availableWidth = width - margin.left - margin.right;
    const availableHeight = height - margin.top - margin.bottom;
    const scale = Math.min(availableWidth / roomLength, availableHeight / roomWidth);
    const mapWidth = roomLength * scale;
    const mapHeight = roomWidth * scale;
    const originX = margin.left + (availableWidth - mapWidth) / 2;
    const originY = margin.top + (availableHeight - mapHeight) / 2;
    const cellWidth = mapWidth / nx;
    const cellHeight = mapHeight / ny;
    const targetLux = Math.max(1, Number(result.metrics?.target_lux || 1));

    context.clearRect(0, 0, width, height);
    context.fillStyle = '#0b1020';
    context.fillRect(0, 0, width, height);
    for (let y = 0; y < ny; y++) {
      for (let x = 0; x < nx; x++) {
        const sourceY = ny - 1 - y;
        const lux = Number(values[sourceY * nx + x] || 0);
        const [red, green, blue] = colorForRatio(lux / targetLux);
        context.fillStyle = `rgb(${red},${green},${blue})`;
        context.fillRect(originX + x * cellWidth, originY + y * cellHeight, Math.ceil(cellWidth + .4), Math.ceil(cellHeight + .4));
      }
    }
    context.strokeStyle = 'rgba(255,255,255,.8)';
    context.lineWidth = 2;
    context.strokeRect(originX, originY, mapWidth, mapHeight);

    (result.layout?.fixtures || []).forEach(fixture => {
      const x = originX + Number(fixture.x_m || 0) / roomLength * mapWidth;
      const y = originY + mapHeight - Number(fixture.y_m || 0) / roomWidth * mapHeight;
      context.beginPath();
      context.arc(x, y, 4.5, 0, Math.PI * 2);
      context.fillStyle = '#fff';
      context.fill();
      context.strokeStyle = 'rgba(8,16,35,.8)';
      context.lineWidth = 1.5;
      context.stroke();
    });

    context.fillStyle = '#b6c1d4';
    context.font = '16px system-ui, sans-serif';
    context.textAlign = 'center';
    context.fillText(`${number(roomLength, 1)} m`, originX + mapWidth / 2, originY + mapHeight + 35);
    context.save();
    context.translate(originX - 35, originY + mapHeight / 2);
    context.rotate(-Math.PI / 2);
    context.fillText(`${number(roomWidth, 1)} m`, 0, 0);
    context.restore();
    context.textAlign = 'left';
    context.fillStyle = '#7f8ca3';
    context.font = '13px system-ui, sans-serif';
    context.fillText('○ Luminaire position', originX, height - 12);
    state.heatmapGeometry = { nx, ny, values, originX, originY, mapWidth, mapHeight, targetLux };
  }

  function renderResult(payload) {
    state.latest = payload;
    state.savedProject = null;
    reportLink.hidden = true;
    const result = payload.result;
    const metrics = result.metrics || {};
    const layout = result.layout || {};
    empty.hidden = true;
    resultsPanel.hidden = false;
    $('[data-result-title]').textContent = `${payload.product.sku} Lighting Simulation`;
    $('[data-result-subtitle]').textContent = `${payload.product.configured_model} · ${payload.profile.ies.original_name}`;
    $('[data-result-engine]').textContent = `${result.engine_version} · MF ${Number(result.maintenance_factor || .8).toFixed(2)}`;
    $('[data-result-quantity]').textContent = number(layout.quantity || 1);
    $('[data-result-layout]').textContent = `${layout.columns || 1} × ${layout.rows || 1} layout`;
    $('[data-result-average]').textContent = number(metrics.average_lux);
    $('[data-result-maximum]').textContent = number(metrics.maximum_lux);
    $('[data-result-minimum]').textContent = number(metrics.minimum_lux);
    $('[data-result-uniformity]').textContent = Number(metrics.uniformity_u0 || 0).toFixed(2);
    $('[data-result-target]').textContent = `Target ${number(metrics.target_lux)} lx · ${metrics.target_met ? 'met' : 'not met'}`;
    $('[data-result-arrangement]').textContent = `${layout.columns || 1} columns × ${layout.rows || 1} rows`;
    $('[data-result-spacing-x]').textContent = `${number(layout.spacing_x_m || 0, 2)} m`;
    $('[data-result-spacing-y]').textContent = `${number(layout.spacing_y_m || 0, 2)} m`;
    $('[data-result-beam]').textContent = payload.profile.beam_angle_deg
      ? `${number(payload.profile.beam_angle_deg, 1)}°`
      : 'Derived profile';
    const singleSummary = $('[data-single-summary]');
    const single = result.single;
    if (singleSummary) {
      singleSummary.hidden = !single;
      if (single) {
        $('[data-single-centre]').textContent = number(single.center_lux);
        $('[data-single-edge-c0]').textContent = number(single.edge_lux_c0);
        $('[data-single-edge-c90]').textContent = number(single.edge_lux_c90);
        $('[data-single-spot-c0]').textContent = number(single.spot_diameter_c0_m, 2);
        $('[data-single-spot-c90]').textContent = number(single.spot_diameter_c90_m, 2);
      }
    }
    $('[data-result-map-caption]').textContent = `${number(result.room.length_m, 1)} × ${number(result.room.width_m, 1)} m · target ${number(metrics.target_lux)} lx`;
    const warnings = [...(result.warnings || []), ...(result.assumptions || [])];
    $('[data-result-warnings]').innerHTML = warnings.map(warning => `<li>${escapeHtml(warning)}</li>`).join('');
    drawHeatmap(result);
    resultsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function saveProject() {
    if (state.savedProject) return state.savedProject;
    if (!state.latest?.simulation_token) throw new Error('Run the simulation before saving it.');
    saveButton.disabled = true;
    const original = saveButton.textContent;
    saveButton.textContent = 'Saving…';
    try {
      const payload = await jsonRequest('api/lighting-project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          simulation_token: state.latest.simulation_token,
          project_name: projectNameInput.value.trim()
        })
      });
      state.savedProject = payload.project;
      reportLink.href = payload.project.report.url;
      reportLink.hidden = false;
      saveButton.textContent = 'Simulation Saved';
      setStatus(`Saved as ${payload.project.id}. The verified PDF report is ready.`, 'success');
      return payload.project;
    } finally {
      saveButton.disabled = false;
      if (!state.savedProject) saveButton.textContent = original;
    }
  }

  async function productMoq(sku) {
    try {
      const payload = await jsonRequest(`api/configure.php?sku=${encodeURIComponent(sku)}`);
      return Math.max(1, Math.ceil(Number(payload.data?.product?.moq || 1)));
    } catch {
      return 1;
    }
  }

  async function addSimulationToCart() {
    if (!state.latest) throw new Error('Run the simulation first.');
    const project = await saveProject();
    const recommended = Math.max(1, Number(state.latest.result.layout?.quantity || 1));
    const minimum = await productMoq(state.latest.product.sku);
    const quantity = Math.max(recommended, minimum);
    const payload = await jsonRequest('api/cart.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'Idempotency-Key': idempotencyKey()
      },
      body: JSON.stringify({
        action: 'add',
        item: {
          sku: state.latest.product.sku,
          configuration: state.latest.product.configuration,
          quantity,
          customer_note: quantity > recommended
            ? `IES simulation recommended ${recommended} pcs; cart quantity adjusted to MOQ ${minimum} pcs.`
            : 'Quantity from saved Lighting Simulation.',
          simulation_project_id: project.id
        }
      })
    });
    window.dispatchEvent(new CustomEvent('artdon-cart-server-update', { detail: { cart: payload.data.cart } }));
    addCartButton.textContent = 'Added — View Project Cart';
    addCartButton.dataset.added = 'true';
    setStatus(`${quantity} pcs added to Project Cart with simulation ${project.id}.`, 'success');
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    submitButton.disabled = true;
    const original = submitButton.textContent;
    submitButton.textContent = 'Calculating IES distribution…';
    setStatus('Evaluating layout candidates and illuminance grid…');
    try {
      const payload = await jsonRequest('api/lighting-simulate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(formPayload())
      });
      renderResult(payload);
      setStatus(`Calculation complete. Result token expires at ${new Date(payload.expires_at).toLocaleTimeString()}.`, 'success');
    } catch (error) {
      setStatus(error.message, 'error');
    } finally {
      submitButton.disabled = !selectedProfile();
      submitButton.textContent = original;
    }
  });

  saveButton.addEventListener('click', () => saveProject().catch(error => setStatus(error.message, 'error')));
  addCartButton.addEventListener('click', () => {
    if (addCartButton.dataset.added === 'true') {
      window.location.href = route('cart');
      return;
    }
    addCartButton.disabled = true;
    addSimulationToCart()
      .catch(error => setStatus(error.message, 'error'))
      .finally(() => { addCartButton.disabled = false; });
  });
  productSelect.addEventListener('change', renderProfile);
  roomType.addEventListener('change', () => updateRoomGuidance(true));

  canvas?.addEventListener('mousemove', event => {
    const geometry = state.heatmapGeometry;
    if (!geometry) return;
    const rect = canvas.getBoundingClientRect();
    const x = (event.clientX - rect.left) * canvas.width / rect.width;
    const y = (event.clientY - rect.top) * canvas.height / rect.height;
    const { originX, originY, mapWidth, mapHeight, nx, ny, values } = geometry;
    if (x < originX || y < originY || x > originX + mapWidth || y > originY + mapHeight) {
      tooltip.hidden = true;
      return;
    }
    const ix = Math.min(nx - 1, Math.floor((x - originX) / mapWidth * nx));
    const displayY = Math.min(ny - 1, Math.floor((y - originY) / mapHeight * ny));
    const sourceY = ny - 1 - displayY;
    tooltip.textContent = `${number(values[sourceY * nx + ix])} lux`;
    tooltip.hidden = false;
    tooltip.style.left = `${event.clientX - rect.left + 12}px`;
    tooltip.style.top = `${event.clientY - rect.top + 12}px`;
  });
  canvas?.addEventListener('mouseleave', () => { tooltip.hidden = true; });

  $('[data-simulation-ai-form]')?.addEventListener('submit', async event => {
    event.preventDefault();
    const brief = $('[data-simulation-ai-brief]').value.trim();
    const output = $('[data-simulation-ai-result]');
    if (!brief) return;
    try {
      const payload = await jsonRequest('api/ai-recommend.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ brief })
      });
      const advice = payload.advice;
      roomType.value = advice.room_type;
      targetInput.value = advice.target_lux.recommended;
      form.elements.installation_height_m.value = advice.installation_height_m;
      form.elements.mounting_type.value = advice.mounting_type;
      updateRoomGuidance(false);
      const recommendedSku = advice.shortlist?.find(item =>
        state.products.some(product => product.sku === item.sku)
      )?.sku;
      if (recommendedSku) {
        const option = Array.from(productSelect.options).find(item =>
          state.profiles.get(item.value)?.product.sku === recommendedSku
        );
        if (option) {
          productSelect.value = option.value;
          renderProfile();
        }
      }
      output.hidden = false;
      output.innerHTML = `<strong>${escapeHtml(advice.room_type)} · ${escapeHtml(advice.target_lux.recommended)} lux · ${escapeHtml(advice.beam_angle)}</strong><p>${escapeHtml(advice.quantity_guidance)} ${escapeHtml(advice.disclaimer)}</p>`;
    } catch (error) {
      output.hidden = false;
      output.innerHTML = `<strong>Suggestion unavailable</strong><p>${escapeHtml(error.message)}</p>`;
    }
  });

  updateRoomGuidance(false);
  loadProducts();
})();
