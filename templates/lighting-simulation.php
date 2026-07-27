<?php

declare(strict_types=1);

$initialProduct = trim((string) ($_GET['product'] ?? ''));
?>
<section class="simulation-hero">
    <div class="container simulation-hero-grid">
        <div>
            <span class="eyebrow eyebrow-light">AI Lighting Simulation · IES</span>
            <h1>Turn a product’s photometry into a preliminary lighting plan.</h1>
            <p>Enter the room, target illuminance and mounting conditions. The engine reads the selected IES profile, estimates fixture quantity and spacing, and draws a false-colour lux map.</p>
            <div class="simulation-flow" aria-label="Simulation workflow">
                <span><b>01</b> Define space</span>
                <span><b>02</b> Select photometry</span>
                <span><b>03</b> Simulate</span>
                <span><b>04</b> Save to project</span>
            </div>
        </div>
        <aside>
            <strong>Preliminary direct-illuminance estimate</strong>
            <p>V1 calculates a horizontal plane from Type C, TILT=NONE IES data. Reflections, daylight, obstructions, glare and vertical illuminance are outside this calculation.</p>
        </aside>
    </div>
</section>

<section class="section simulation-section" data-lighting-simulation data-initial-product="<?= e($initialProduct) ?>">
    <div class="container">
        <div class="simulation-ai-intake">
            <div>
                <?= icon('ai') ?>
                <span>
                    <strong>Describe the project in one sentence</strong>
                    <small>The guided assistant will suggest a room type, target lux, mounting and product starting point.</small>
                </span>
            </div>
            <form data-simulation-ai-form>
                <input type="text" maxlength="1200" placeholder="Example: I need lighting for a hotel lobby, 5 m high." data-simulation-ai-brief>
                <button class="button button-light" type="submit">Suggest setup <?= icon('arrow') ?></button>
            </form>
            <div class="simulation-ai-result" data-simulation-ai-result hidden></div>
        </div>

        <div class="simulation-workspace">
            <aside class="simulation-controls">
                <form data-simulation-form>
                    <section>
                        <header><span>01</span><div><strong>Product photometry</strong><small>Select the optical profile to calculate.</small></div></header>
                        <label class="simulation-field">
                            <span>Product / IES profile</span>
                            <select name="ies_profile_id" required data-simulation-product>
                                <option value="">Loading simulation-ready products…</option>
                            </select>
                        </label>
                        <div class="simulation-profile-card" data-simulation-profile>
                            <span>No profile selected</span>
                            <small>Only validated or clearly labelled preliminary profiles are shown.</small>
                        </div>
                    </section>

                    <section>
                        <header><span>02</span><div><strong>Space parameters</strong><small>Dimensions use metres; illuminance uses lux.</small></div></header>
                        <label class="simulation-field">
                            <span>Room type</span>
                            <select name="room_type" data-simulation-room-type>
                                <option value="retail">Retail</option>
                                <option value="office">Office</option>
                                <option value="hotel">Hotel</option>
                                <option value="restaurant">Restaurant</option>
                                <option value="gallery">Gallery</option>
                                <option value="museum">Museum</option>
                                <option value="residential">Residential</option>
                                <option value="warehouse">Warehouse</option>
                            </select>
                        </label>
                        <div class="simulation-number-grid">
                            <label class="simulation-field"><span>Length</span><div><input type="number" name="length_m" min="0.5" max="100" step="0.1" value="10" required><b>m</b></div></label>
                            <label class="simulation-field"><span>Width</span><div><input type="number" name="width_m" min="0.5" max="100" step="0.1" value="8" required><b>m</b></div></label>
                            <label class="simulation-field"><span>Room height</span><div><input type="number" name="height_m" min="1" max="30" step="0.1" value="4" required><b>m</b></div></label>
                            <label class="simulation-field"><span>Installation height</span><div><input type="number" name="installation_height_m" min="0.2" max="30" step="0.1" value="3.2" required><b>m</b></div></label>
                        </div>
                        <label class="simulation-field">
                            <span>Mounting type</span>
                            <select name="mounting_type">
                                <option value="recessed">Recessed</option>
                                <option value="track">Track</option>
                                <option value="surface">Surface</option>
                                <option value="pendant">Pendant</option>
                                <option value="linear">Linear</option>
                            </select>
                        </label>
                        <label class="simulation-field">
                            <span>Target illuminance</span>
                            <div><input type="number" name="target_lux" min="10" max="5000" step="10" value="400" required data-simulation-target><b>lux</b></div>
                            <small data-simulation-target-note>Retail starting point: 300–500 lux. Confirm the applicable project standard.</small>
                        </label>
                    </section>

                    <section>
                        <header><span>03</span><div><strong>Calculation mode</strong><small>Compare one fixture or generate a regular layout.</small></div></header>
                        <div class="simulation-mode-picker">
                            <label><input type="radio" name="mode" value="auto_layout" checked><span><strong>Auto Layout</strong><small>Find the minimum regular layout that reaches average target lux.</small></span></label>
                            <label><input type="radio" name="mode" value="one_light"><span><strong>One Light</strong><small>Inspect centre lux, beam footprint and edge falloff.</small></span></label>
                        </div>
                    </section>

                    <button class="button button-blue button-large button-block" type="submit" data-simulation-submit>Run Lighting Simulation <?= icon('arrow') ?></button>
                    <p class="simulation-form-status" data-simulation-status aria-live="polite"></p>
                </form>
            </aside>

            <div class="simulation-output">
                <div class="simulation-empty" data-simulation-empty>
                    <div class="simulation-empty-graphic">
                        <span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                    <span class="eyebrow">Simulation display</span>
                    <h2>Your false-colour illuminance map will appear here.</h2>
                    <p>Choose a simulation-ready product, confirm the space and run the calculation.</p>
                </div>

                <div class="simulation-results" data-simulation-results hidden>
                    <header class="simulation-result-head">
                        <div><span class="eyebrow">Calculated result</span><h2 data-result-title>Lighting Simulation</h2><p data-result-subtitle></p></div>
                        <span class="simulation-engine-badge" data-result-engine>Direct IES V1</span>
                    </header>
                    <div class="simulation-metrics">
                        <article><span>Recommended quantity</span><strong><b data-result-quantity>—</b> pcs</strong><small data-result-layout>—</small></article>
                        <article><span>Average</span><strong><b data-result-average>—</b> lx</strong><small data-result-target>Target — lx</small></article>
                        <article><span>Maximum</span><strong><b data-result-maximum>—</b> lx</strong><small>Calculated plane</small></article>
                        <article><span>Minimum</span><strong><b data-result-minimum>—</b> lx</strong><small>Calculated plane</small></article>
                        <article><span>Uniformity U₀</span><strong data-result-uniformity>—</strong><small>Emin / Eavg</small></article>
                    </div>
                    <div class="simulation-metrics simulation-single-summary" data-single-summary hidden>
                        <article><span>Centre illuminance</span><strong><b data-single-centre>—</b> lx</strong><small>Directly below luminaire</small></article>
                        <article><span>Beam-edge C0</span><strong><b data-single-edge-c0>—</b> lx</strong><small>At half-peak beam boundary</small></article>
                        <article><span>Beam-edge C90</span><strong><b data-single-edge-c90>—</b> lx</strong><small>At half-peak beam boundary</small></article>
                        <article><span>Spot diameter C0</span><strong><b data-single-spot-c0>—</b> m</strong><small>Geometric beam footprint</small></article>
                        <article><span>Spot diameter C90</span><strong><b data-single-spot-c90>—</b> m</strong><small>Geometric beam footprint</small></article>
                    </div>

                    <div class="simulation-map-card">
                        <header>
                            <div><strong>False Colour Heatmap</strong><small data-result-map-caption>Horizontal calculation plane</small></div>
                            <div class="simulation-legend" aria-label="Illuminance legend">
                                <span><i style="--legend:#14288f"></i>Low</span>
                                <span><i style="--legend:#12b8c4"></i></span>
                                <span><i style="--legend:#8bd646"></i>Target</span>
                                <span><i style="--legend:#ffc52a"></i></span>
                                <span><i style="--legend:#ef3e31"></i>High</span>
                            </div>
                        </header>
                        <div class="simulation-canvas-wrap">
                            <canvas width="900" height="620" data-simulation-heatmap aria-label="False-colour illuminance heatmap"></canvas>
                            <div class="simulation-map-tooltip" data-simulation-tooltip hidden></div>
                        </div>
                    </div>

                    <div class="simulation-detail-grid">
                        <article>
                            <span class="eyebrow">Layout recommendation</span>
                            <dl>
                                <div><dt>Arrangement</dt><dd data-result-arrangement>—</dd></div>
                                <div><dt>X spacing</dt><dd data-result-spacing-x>—</dd></div>
                                <div><dt>Y spacing</dt><dd data-result-spacing-y>—</dd></div>
                                <div><dt>Beam</dt><dd data-result-beam>—</dd></div>
                            </dl>
                        </article>
                        <article>
                            <span class="eyebrow">Calculation notes</span>
                            <ul data-result-warnings><li>Run the simulation to review assumptions and advisories.</li></ul>
                        </article>
                    </div>

                    <div class="simulation-result-actions">
                        <label>Project name<input type="text" maxlength="160" placeholder="Example: Singapore Hotel Lobby" data-simulation-project-name></label>
                        <button type="button" class="button button-dark" data-simulation-save>Save &amp; Generate PDF</button>
                        <button type="button" class="button button-outline" data-simulation-add-cart>Add Result to Project Cart</button>
                        <a class="button button-outline" href="#" target="_blank" rel="noopener" data-simulation-report hidden>Download Report</a>
                        <small class="simulation-save-note">Until customer login is connected, saved simulations and reports are available only in this browser session.</small>
                    </div>
                    <p class="simulation-disclaimer">Preliminary direct-illuminance estimate. Final construction design must be verified by a qualified lighting designer or professional lighting software.</p>
                </div>
            </div>
        </div>
    </div>
</section>
