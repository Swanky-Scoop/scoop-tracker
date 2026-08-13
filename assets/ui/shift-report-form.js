///////////////////////////////////
// ShiftReportForm — end-of-shift report, one submission per shift (see
// WHITEBOARD-INGESTION.md). Deliberately NOT a List/Tile subclass: List's
// machinery (cells/dirty-tracking/autosave, one POST per model to a single
// generic per-type route) is built around editing persisted spreadsheet
// rows, and has no vocabulary for a checkbox checklist, a multi-select
// relationship picker, a repeatable "create new record" sub-form, or a file
// upload — forcing those into columns would mean extending shared
// List/Tile/Grid infrastructure for one one-off form. Standalone, same
// independence precedent as PopularPlot (see scoop-api.js's analytics-grid
// branch) — but bundle-fed like every other grid, not self-fetching, so it
// only needs to satisfy the same external contract ScoopAPI actually calls
// on a bundle-fed view: setDomain(domain), and an optional dockToggle()
// (omitted here — no dock button for a one-time submit form, it just
// renders inline).
//////////////////////////////////
import El from "./_el.js";
import Toast from "./toast.js";
import PageStatus from "./page-status.js";
import { CHANGE_LOW_OPTIONS, STAFFING_LEVEL_OPTIONS } from "../models/shift-report-grid-model.js";

export default class ShiftReportForm extends El {
  constructor(dom, type, { api, modelInstance, pageStatusId = null } = {}) {
    super();
    this.dom = dom;
    this.name = type;
    this.api = api;
    this.modelInstance = modelInstance;
    this.pageStatusId = pageStatusId;

    this._photoId = null;
    this._cakeOrderRows = []; // [{ li, orderName, cakePieFlavor, pickupDate, details }]

    this._buildDom();
  }

  // External contract ScoopAPI actually calls (see scoop-api.js's
  // bundleGrids.forEach(g => g.setDomain(...))) — applies the fetched
  // domain to the model, then (re)populates the flavor/supply/location
  // <select>s. Doesn't rebuild the whole form on a later refresh — a
  // background bundle refresh landing mid-fill (e.g. someone else on the
  // page saving something) must not wipe out-of-progress input, same
  // reasoning as Closeout's repaintOnRefresh=false.
  //
  // PageStatus.register() (scoop-api.js's mountAllGrids) marks every grid
  // host 'unknown' at mount time regardless of view type — only
  // PageStatus.setState(id, 'fresh') clears it, which List calls itself
  // (_reportFresh) but this standalone view doesn't inherit. Without this,
  // the shimmer never clears, AND — worse — PageStatus's page-wide
  // indicator shows the WORST state across every registered grid, so a
  // stuck ShiftReport host holds the entire page at 'unknown' forever, not
  // just its own <li>.
  async setDomain(domain) {
    this.modelInstance.setDomain(domain);
    this._populateLocationSelect();
    this._renderFlavorsChangedChecklist();
    this._renderSuppliesLowChecklist();
    if (this.pageStatusId) PageStatus.setState(this.pageStatusId, 'fresh');
  }

  _buildDom() {
    const el = this.el;

    this.ROOT = el('form', { classes: ['shift-report-form'] });
    this.ROOT.append(el('h2', { text: 'End-of-shift report' }));

    // ── Tempering cabinet photo (required, single) ──────────────────
    this.ROOT.append(el('h3', { text: 'Tempering cabinet photo' }));
    // capture="environment" biases mobile/tablet browsers toward opening the
    // rear camera directly rather than a gallery picker — appropriate here
    // since this field is meant to capture what's actually in the cabinet
    // right now, not an old photo (see WHITEBOARD-INGESTION.md's "audit
    // signal" reasoning). Desktop browsers ignore the attribute and just
    // show a normal file picker. Some mobile browsers hide the "choose
    // existing photo" option entirely when this is set — accepted here as
    // the right default for a same-moment freshness check.
    this.PHOTO_INPUT = el('input', { attrs: { type: 'file', accept: 'image/*', capture: 'environment', required: true } });
    this.PHOTO_PREVIEW = el('div', { classes: ['photo-preview'] });
    this.PHOTO_INPUT.addEventListener('change', () => this._onPhotoSelected());
    this.ROOT.append(this.PHOTO_INPUT, this.PHOTO_PREVIEW);

    // ── Flavors changed out (multi-relationship, checkboxes) ──────────
    // Source list is today's slot.current_flavor, filtered to the selected
    // location and grouped by cabinet (see
    // ShiftReportGridModel.currentFlavorsByCabinet) — only flavors actually
    // in a cabinet slot right now are worth asking about, not the whole
    // flavor catalog. Rebuilt whenever the location changes (see the
    // LOCATION_SELECT listener below), since a different location's
    // cabinets hold different flavors.
    this.ROOT.append(el('h3', { text: 'Flavors changed out' }));
    this.FLAVORS_CHANGED_WRAP = el('div', { classes: ['flavors-changed-checklist'] });
    this.ROOT.append(this.FLAVORS_CHANGED_WRAP);

    // ── Supplies low (multi-relationship, checkboxes grouped by category) ──
    // Source is the 'supply' pod's own 'group' field (see
    // WHITEBOARD-INGESTION.md — 83 items populated on local), grouped and
    // ordered via ShiftReportGridModel.supplyOptionsByGroup().
    this.ROOT.append(el('h3', { text: 'Supplies: I noticed we are running out of' }));
    this.SUPPLIES_LOW_WRAP = el('div', { classes: ['supplies-low-checklist'] });
    this.ROOT.append(this.SUPPLIES_LOW_WRAP);

    // ── Cash discrepancy / change low ─────────────────────────────────
    this.CASH_DISCREPANCY = el('input', { attrs: { type: 'number', step: '0.01' } });
    this.ROOT.append(this._labeled('Known cash discrepancies', this.CASH_DISCREPANCY));

    this.ROOT.append(el('h3', { text: 'Running low on any change or small bills?' }));
    const CHANGE_WRAP = el('ul', { classes: ['change-low-checklist'] });
    this._changeLowCheckboxes = [];
    for (const denom of CHANGE_LOW_OPTIONS) {
      const ID = `change-low-${this._slug(denom)}`;
      const CB = el('input', { attrs: { type: 'checkbox', id: ID, value: denom } });
      this._changeLowCheckboxes.push(CB);
      const LABEL = el('label', { attrs: { for: ID } });
      LABEL.append(CB, ` ${denom}`);
      const LI = el('li');
      LI.append(LABEL);
      CHANGE_WRAP.append(LI);
    }
    this.ROOT.append(CHANGE_WRAP);

    // ── Cake orders — create new records inline, not a picker ─────────
    this.ROOT.append(el('h3', { text: '# of cake orders taken today' }));
    this.CAKE_ORDERS = el('div', { classes: ['cake-orders'] });
    this.ADD_CAKE_ORDER = el('button', {
      text: '+ Add cake order', classes: ['add-cake-order'], attrs: { type: 'button' },
    });
    this.ADD_CAKE_ORDER.addEventListener('click', () => this._addCakeOrderRow());
    this.ROOT.append(this.CAKE_ORDERS, this.ADD_CAKE_ORDER);

    // ── Remaining text fields ──────────────────────────────────────────
    this.FINAL_TASKS = el('textarea', {});
    this.ROOT.append(this._labeled('Final tasks', this.FINAL_TASKS));

    this.POSITIVE_FEEDBACK = el('textarea', {});
    this.ROOT.append(this._labeled('Were you able to hand out 10 pieces of positive feedback?', this.POSITIVE_FEEDBACK));

    this.CUSTOMER_ISSUES = el('textarea', {});
    this.ROOT.append(this._labeled('Customer issues', this.CUSTOMER_ISSUES));

    this.NOTES_FOR_TOMORROW = el('textarea', {});
    this.ROOT.append(this._labeled('Any notes for tomorrow?', this.NOTES_FOR_TOMORROW));

    this.STAFFING_LEVEL = this._buildSelect(
      'staffing_level',
      STAFFING_LEVEL_OPTIONS.map(v => ({ id: v, title: v })),
      'staffing level',
    );
    this.ROOT.append(this._labeled('Did you have too many or not enough scoopers?', this.STAFFING_LEVEL));

    this.LOCATION_SELECT = this._buildSelect('location', [], 'Location');
    // A different location has different cabinets/slots — the flavors
    // checklist has to rebuild for whichever location is actually selected,
    // not just whatever the shortcode's default was at mount time.
    this.LOCATION_SELECT.addEventListener('change', () => this._renderFlavorsChangedChecklist());
    this.ROOT.append(this._labeled('Location', this.LOCATION_SELECT));

    // ── Submit ──────────────────────────────────────────────────────
    this.SUBMIT = el('button', { text: 'Submit report', attrs: { type: 'submit' } });
    this.ROOT.append(this.SUBMIT);

    this.ROOT.addEventListener('submit', (e) => {
      e.preventDefault();
      this._submit();
    });

    this.dom.replaceChildren(this.ROOT);
  }

  _labeled(text, field) {
    const WRAP = this.el('label', { classes: ['field'] });
    WRAP.append(this.el('span', { text }), field);
    return WRAP;
  }

  _buildSelect(name, options, placeholder) {
    const SELECT = this.el('select', { attrs: { name } });
    SELECT.append(this.el('option', { text: `— ${placeholder} —`, attrs: { value: '' } }));
    for (const opt of options) {
      SELECT.append(this.el('option', { text: opt.title, attrs: { value: opt.id } }));
    }
    return SELECT;
  }

  _slug(text) {
    return String(text).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  }

  // Rebuilds the flavors-changed checklist from
  // ShiftReportGridModel.currentFlavorsByCabinet() for whichever location is
  // currently selected. Preserves already-checked flavors across a rebuild
  // (location change, or a background domain refresh) the same way
  // _populateMultiSelect preserves selections — a rebuild triggered by
  // something other than the user's own location change shouldn't discard
  // their in-progress picks.
  _renderFlavorsChangedChecklist() {
    const el = this.el;
    const locationId = Number(this.LOCATION_SELECT?.value || this.modelInstance.location || 0);
    const previouslyChecked = new Set(
      (this._flavorsChangedCheckboxes ?? []).filter(cb => cb.checked).map(cb => cb.value)
    );

    this.FLAVORS_CHANGED_WRAP.replaceChildren();
    this._flavorsChangedCheckboxes = [];

    const groups = this.modelInstance.currentFlavorsByCabinet(locationId);

    if (!groups.length) {
      this.FLAVORS_CHANGED_WRAP.append(el('p', {
        text: 'No cabinet slots with a flavor found for this location.',
        classes: ['empty-note'],
      }));
      return;
    }

    for (const group of groups) {
      this.FLAVORS_CHANGED_WRAP.append(el('h4', { text: group.cabinetTitle }));
      const UL = el('ul', { classes: ['flavor-group'] });

      for (const flavor of group.flavors) {
        // Id is per (cabinet, flavor), not just per flavor — the same
        // flavor can legitimately show up in more than one cabinet, and
        // DOM ids must stay unique. Collection below dedupes back to one
        // entry per flavor id regardless of how many cabinets it's checked
        // under.
        const ID = `flavor-changed-${group.cabinetId}-${flavor.id}`;
        const CB = el('input', { attrs: { type: 'checkbox', id: ID, value: flavor.id } });
        if (previouslyChecked.has(String(flavor.id))) CB.checked = true;
        this._flavorsChangedCheckboxes.push(CB);

        const LABEL = el('label', { attrs: { for: ID } });
        LABEL.append(CB, ` ${flavor.title}`);
        const LI = el('li');
        LI.append(LABEL);
        UL.append(LI);
      }
      this.FLAVORS_CHANGED_WRAP.append(UL);
    }
  }

  _collectFlavorsChanged() {
    const ids = new Set();
    for (const cb of this._flavorsChangedCheckboxes ?? []) {
      if (cb.checked) ids.add(Number(cb.value));
    }
    return [...ids];
  }

  // Rebuilds the supplies-low checklist from
  // ShiftReportGridModel.supplyOptionsByGroup(). No location-scoping needed
  // (supply isn't location-specific), so unlike flavors-changed this only
  // needs to run once, from setDomain() — but still preserves already-
  // checked items the same way, in case a background domain refresh lands
  // mid-fill.
  _renderSuppliesLowChecklist() {
    const el = this.el;
    const previouslyChecked = new Set(
      (this._suppliesLowCheckboxes ?? []).filter(cb => cb.checked).map(cb => cb.value)
    );

    this.SUPPLIES_LOW_WRAP.replaceChildren();
    this._suppliesLowCheckboxes = [];

    const groups = this.modelInstance.supplyOptionsByGroup();

    if (!groups.length) {
      this.SUPPLIES_LOW_WRAP.append(el('p', {
        text: 'No supply items found.',
        classes: ['empty-note'],
      }));
      return;
    }

    for (const group of groups) {
      this.SUPPLIES_LOW_WRAP.append(el('h4', { text: group.group }));
      const UL = el('ul', { classes: ['supply-group'] });

      for (const supply of group.supplies) {
        const ID = `supply-low-${supply.id}`;
        const CB = el('input', { attrs: { type: 'checkbox', id: ID, value: supply.id } });
        if (previouslyChecked.has(String(supply.id))) CB.checked = true;
        this._suppliesLowCheckboxes.push(CB);

        const LABEL = el('label', { attrs: { for: ID } });
        LABEL.append(CB, ` ${supply.title}`);
        const LI = el('li');
        LI.append(LABEL);
        UL.append(LI);
      }
      this.SUPPLIES_LOW_WRAP.append(UL);
    }
  }

  _collectSuppliesLow() {
    return (this._suppliesLowCheckboxes ?? [])
      .filter(cb => cb.checked)
      .map(cb => Number(cb.value));
  }

  _populateLocationSelect() {
    const current = this.LOCATION_SELECT.value;
    const options = this.modelInstance.locationOptions();
    this.LOCATION_SELECT.replaceChildren();
    this.LOCATION_SELECT.append(this.el('option', { text: '— Location —', attrs: { value: '' } }));
    for (const loc of options) {
      this.LOCATION_SELECT.append(this.el('option', { text: loc.title, attrs: { value: loc.id } }));
    }
    if (current) this.LOCATION_SELECT.value = current;
    else if (this.modelInstance.location) this.LOCATION_SELECT.value = String(this.modelInstance.location);
  }

  // cake_pie_flavor is a Pods custom Pick list, option values not yet known
  // (see WHITEBOARD-INGESTION.md) — plain text input as a stand-in until
  // confirmed, then this becomes a _buildSelect() like staffing_level.
  _addCakeOrderRow() {
    const el = this.el;
    const LI = el('div', { classes: ['cake-order-row'] });

    const orderName = el('input', { attrs: { type: 'text', placeholder: 'Order name' } });
    const cakePieFlavor = el('input', { attrs: { type: 'text', placeholder: 'Cake/pie flavor' } });
    const pickupDate = el('input', { attrs: { type: 'date' } });
    const details = el('textarea', { attrs: { placeholder: 'Details' } });
    const remove = el('button', { text: 'Remove', classes: ['remove-cake-order'], attrs: { type: 'button' } });

    remove.addEventListener('click', () => {
      LI.remove();
      this._cakeOrderRows = this._cakeOrderRows.filter(r => r.li !== LI);
    });

    LI.append(
      this._labeled('Order name', orderName),
      this._labeled('Cake/pie flavor', cakePieFlavor),
      this._labeled('Pickup date', pickupDate),
      this._labeled('Details', details),
      remove,
    );
    this.CAKE_ORDERS.append(LI);

    this._cakeOrderRows.push({ li: LI, orderName, cakePieFlavor, pickupDate, details });
  }

  async _onPhotoSelected() {
    const file = this.PHOTO_INPUT.files?.[0];
    if (!file) return;

    this.PHOTO_INPUT.disabled = true;
    this.PHOTO_PREVIEW.replaceChildren(this.el('span', { text: `Uploading ${file.name}…`, classes: ['photo-uploading'] }));

    try {
      this._photoId = await this._uploadPhoto(file);
      const img = this.el('img', { attrs: { src: URL.createObjectURL(file), alt: file.name } });
      this.PHOTO_PREVIEW.replaceChildren(img);
    } catch (err) {
      this._photoId = null;
      this.PHOTO_PREVIEW.replaceChildren(this.el('span', { text: `Failed: ${file.name}`, classes: ['photo-error'] }));
      console.error('Photo upload failed:', err);
    } finally {
      this.PHOTO_INPUT.disabled = false;
    }
  }

  // Uploads straight to WP core's own media endpoint (wp/v2/media), not a
  // scoop/v1 route — standard WP REST nonce (SCOOP.nonce, wp_rest action)
  // authorizes core routes the same as this plugin's own, so no separate
  // auth path is needed. Returns the created attachment's post id.
  async _uploadPhoto(file) {
    const url = new URL('/wp-json/wp/v2/media', window.location.origin);
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Disposition': `attachment; filename="${file.name.replace(/"/g, '')}"`,
        'Content-Type': file.type || 'application/octet-stream',
        ...(this.api?.nonce ? { 'X-WP-Nonce': this.api.nonce } : {}),
      },
      body: file,
    });

    if (!res.ok) throw new Error(`Upload failed: HTTP ${res.status}`);
    const data = await res.json();
    if (!data?.id) throw new Error('Upload succeeded but no media id returned.');
    return data.id;
  }

  _collectChangeLow() {
    return this._changeLowCheckboxes.filter(cb => cb.checked).map(cb => cb.value);
  }

  _collectNewCakeOrders() {
    return this._cakeOrderRows
      .map(r => ({
        order_name: r.orderName.value || '',
        cake_pie_flavor: r.cakePieFlavor.value || '',
        pickup_date: r.pickupDate.value || '',
        details: r.details.value || '',
      }))
      .filter(o => o.order_name || o.cake_pie_flavor || o.pickup_date || o.details);
  }

  async _submit() {
    if (!this._photoId) {
      Toast.addMessage({ title: 'Photo required', message: 'Upload the tempering cabinet photo before submitting.' });
      return;
    }

    const payload = {
      tempering_cabinet_photo: this._photoId,
      flavors_changed: this._collectFlavorsChanged(),
      supplies_low: this._collectSuppliesLow(),
      cash_discrepancy: this.CASH_DISCREPANCY.value ? Number(this.CASH_DISCREPANCY.value) : 0,
      change_low: this._collectChangeLow(),
      new_cake_orders: this._collectNewCakeOrders(),
      final_tasks: this.FINAL_TASKS.value || '',
      positive_feedback: this.POSITIVE_FEEDBACK.value || '',
      customer_issues: this.CUSTOMER_ISSUES.value || '',
      notes_for_tomorrow: this.NOTES_FOR_TOMORROW.value || '',
      staffing_level: this.STAFFING_LEVEL.value || '',
      location: Number(this.LOCATION_SELECT.value || 0),
    };

    this.SUBMIT.disabled = true;
    this.SUBMIT.textContent = 'Submitting…';

    try {
      const url = new URL('/wp-json/scoop/v1/shift-reports', window.location.origin);
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          ...(this.api?.nonce ? { 'X-WP-Nonce': this.api.nonce } : {}),
        },
        body: JSON.stringify({ ShiftReport: payload }),
      });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.ok) {
        Toast.addMessage({ title: 'Shift report failed', message: data?.error ?? `HTTP ${res.status}` });
        return;
      }

      if (data.cake_order_errors?.length) {
        Toast.addMessage({
          title: 'Report saved, with issues',
          message: `Saved, but ${data.cake_order_errors.length} cake order(s) failed to save — check with a manager.`,
        });
      } else {
        Toast.addMessage({ title: 'Shift report submitted', message: 'Thanks — have a good one.' });
      }

      this._resetForm();
    } catch (err) {
      console.error('Shift report submit failed:', err);
      Toast.addMessage({ title: 'Shift report failed', message: String(err) });
    } finally {
      this.SUBMIT.disabled = false;
      this.SUBMIT.textContent = 'Submit report';
    }
  }

  _resetForm() {
    this.ROOT.reset();
    this._photoId = null;
    this.PHOTO_PREVIEW.replaceChildren();
    this.CAKE_ORDERS.replaceChildren();
    this._cakeOrderRows = [];
    this._populateLocationSelect();
    // Native reset() clears every checkbox's checked state, but the
    // checklist itself was built off whatever location was selected before
    // reset — rebuild it against the (possibly different) post-reset
    // location so it isn't showing a stale set of cabinets/flavors.
    this._renderFlavorsChangedChecklist();
  }
}
