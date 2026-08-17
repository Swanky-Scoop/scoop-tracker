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
// on a bundle-fed view: setDomain(domain), and dockToggle() — every
// shortcode is expected to be dockable (see DOCKING.md), so this extends
// Dockable (assets/ui/_dockable.js) for that piece instead of List.
//
// Schema-driven, not hand-coded: the field list used to be hardcoded here,
// which meant every field added in Pods admin needed a matching code change
// before it would appear (hit this three times while building the earlier
// version — see WHITEBOARD-INGESTION.md). Instead this fetches
// GET /shift-report-fields (scoop_shift_report_field_schema() in rest.php)
// once, and renders one <section> per Pods field GROUP (in Pods admin's own
// order), each containing its fields (also in Pods admin's own order). Only
// four fields need real custom logic no generic renderer could produce —
// tempering_cabinet_photo (upload + camera capture), flavors_changed
// (filtered to today's slot.current_flavor, grouped by cabinet),
// supplies_low (grouped by the supply pod's own category field), and
// cake_orders (creates new cake_order records inline rather than picking
// from existing ones) — those are the only entries in BESPOKE_FIELD_NAMES.
// Everything else, including location, renders generically off field
// metadata (type/label/options) the server already resolved.
//////////////////////////////////
import Dockable from "./_dockable.js";
import Toast from "./toast.js";
import PageStatus from "./page-status.js";

const BESPOKE_FIELD_NAMES = new Set(['tempering_cabinet_photo', 'flavors_changed', 'supplies_low', 'cake_orders']);

export default class ShiftReportForm extends Dockable {
  constructor(dom, type, { api, modelInstance, pageStatusId = null } = {}) {
    super();
    this.dom = dom;
    this.target = dom; // Dockable's methods key off this.target — see _dockable.js.
    this.name = type;
    this.api = api;
    this.modelInstance = modelInstance;
    this.pageStatusId = pageStatusId;

    // setDomain() only ever runs once, at mountAllGrids()'s initial fetch —
    // this view deliberately never rebuilds off a later refresh it didn't
    // cause itself (same "don't repaint mid-fill" reasoning as Batch's own
    // repaintOnRefresh=false), and binds no ts:domain:updated listener at
    // all to do so. ScoopAPI.scopedRefreshTypes() reads this flag to keep
    // this view OUT of a scoped refresh's PageStatus tracking even when its
    // own `needs` genuinely overlaps the trigger's writesPods — otherwise a
    // later Cabinet/FlavorTub/Batch save marks this host 'fetching' again
    // for a fetch it will never report back on, stalling the page's
    // load-timing countdown forever. Doesn't affect the initial page load,
    // which is what actually needs this view's setDomain() to run.
    this.reactsToScopedRefresh = false;

    this._photoId = null;
    this._cakeOrderRows = []; // [{ li, orderName, cakePieFlavor, pickupDate, details }]
    this._fieldGetters = {};  // name -> () => value, for every field except tempering_cabinet_photo/cake_orders
    this._fieldSchema = null; // cached GET /shift-report-fields groups, fetched once
    this.LOCATION_SELECT = null; // set once the (generically-rendered) location field builds, if present

    this.ROOT = this.el('form', { classes: ['shift-report-form'] });
    this.ROOT.append(this.el('h2', { text: 'End-of-shift report' }));
    this.ROOT.append(this.el('p', { text: 'Loading form…', classes: ['loading-note'] }));
    this.ROOT.addEventListener('submit', (e) => {
      e.preventDefault();
      this._submit();
    });

    // Every shortcode is expected to be dockable (see DOCKING.md) — TOGGLE
    // is a sibling of ROOT, same as List's FORM+TOGGLE under target (see
    // _attachCoreDom() in _list.js). ROOT is a real <form>, which already
    // matches css.css's ".in-dock .scoop-grid > form" show/hide rule, so no
    // CSS changes were needed for this one.
    this.TOGGLE = this._buildToggleButton();
    this.dom.replaceChildren(this.ROOT, this.TOGGLE);
    this._bindDockToggle();
    this._bindPageStatusToggle();
  }

  // External contract ScoopAPI actually calls (see scoop-api.js's
  // bundleGrids.forEach(g => g.setDomain(...))). First call: fetches the
  // field schema and builds the real form (replacing the loading
  // placeholder). Later calls (a background bundle refresh landing mid-fill,
  // e.g. someone else on the page saving something): only refresh the two
  // domain-derived bespoke checklists, never rebuild the whole form — same
  // "don't repaint mid-fill" reasoning as Closeout's repaintOnRefresh=false.
  //
  // PageStatus.register() (scoop-api.js's mountAllGrids) marks every grid
  // host 'unknown' at mount time regardless of view type — only
  // PageStatus.setState(id, 'fresh') clears it, which List calls itself
  // (_reportFresh) but this standalone view doesn't inherit. Without this,
  // the shimmer never clears, AND — worse — PageStatus's page-wide
  // indicator shows the WORST state across every registered grid, so a
  // stuck ShiftReport host holds the entire page (and the load-timing
  // countdown — see PageStatus._anyIdsFetching/_tryFinishLoadTiming, which
  // wait on every id's state leaving 'fetching') at 'unknown'/'fetching'
  // forever, not just its own <li>. The setState call below is in `finally`
  // specifically so this reporting can never be skipped by an exception
  // partway through _buildForm()/the checklist renders — a broken render is
  // still better than a page-wide load indicator that never finishes and a
  // countdown that runs forever, and this view's own setDomain() is called
  // fire-and-forget (not awaited) by mountAllGrids(), so nothing else
  // surfaces that failure either.
  async setDomain(domain) {
    this.modelInstance.setDomain(domain);

    try {
      if (!this._fieldSchema) {
        this._fieldSchema = await this._fetchFieldSchema();
        this._buildForm();
      } else {
        this._renderFlavorsChangedChecklist();
        this._renderSuppliesLowChecklist();
      }
    } finally {
      if (this.pageStatusId) PageStatus.setState(this.pageStatusId, 'fresh');
    }
  }

  async _fetchFieldSchema() {
    try {
      const url = new URL('/wp-json/scoop/v1/shift-report-fields', window.location.origin);
      const res = await fetch(url, {
        credentials: 'include',
        headers: { Accept: 'application/json', ...(this.api?.nonce ? { 'X-WP-Nonce': this.api.nonce } : {}) },
      });
      const data = await res.json().catch(() => null);
      return (res.ok && data?.ok && Array.isArray(data.groups)) ? data.groups : [];
    } catch (err) {
      console.error('ShiftReportForm: failed to load field schema:', err);
      return [];
    }
  }

  _buildForm() {
    const el = this.el;
    this.ROOT.replaceChildren();
    this.ROOT.append(el('h2', { text: 'End-of-shift report' }));

    // Un-editable record of who was actually logged in when this form was
    // filled out — separate from shift_lead below, which is a plain text
    // field pre-filled with the same name but still free-text/editable
    // (someone can fill this in on another person's behalf). Same identity
    // string enqueue.php already puts on every page (window.SCOOP.user.name
    // — user_nicename, not a Pods field, so it's DOM-only, not part of the
    // submitted payload built in _submit()).
    this.ROOT.append(el('input', {
      attrs: { type: 'hidden', name: 'authenticated_user', value: window.SCOOP?.user?.name ?? '' },
    }));

    this._fieldGetters = {};
    this.LOCATION_SELECT = null;

    if (!this._fieldSchema.length) {
      this.ROOT.append(el('p', {
        text: 'Could not load the report form. Reload the page to try again.',
        classes: ['empty-note'],
      }));
      return;
    }

    for (const group of this._fieldSchema) {
      const SECTION = el('section', { classes: ['field-group'], data: { group: group.name } });
      SECTION.append(el('h3', { text: group.label }));

      for (const field of group.fields) {
        const NODE = BESPOKE_FIELD_NAMES.has(field.name)
          ? this._buildBespokeField(field)
          : this._buildGenericField(field);
        if (NODE) SECTION.append(NODE);
      }

      this.ROOT.append(SECTION);
    }

    this.SUBMIT = el('button', { text: 'Submit report', attrs: { type: 'submit' } });
    this.ROOT.append(this.SUBMIT);

    // location is generically rendered (just a plain <select> — no bespoke
    // logic of its own), but flavors_changed's cabinet-grouped checklist
    // still needs to know which location is selected and rebuild when it
    // changes, so this one listener bridges a generic field to a bespoke one.
    if (this.LOCATION_SELECT) {
      this.LOCATION_SELECT.addEventListener('change', () => this._renderFlavorsChangedChecklist());
    }
    this._renderFlavorsChangedChecklist();
    this._renderSuppliesLowChecklist();
    this._wireConditionalGroups();
  }

  // Pods has field-level conditional logic but not group-level (checked
  // directly against live field/group data 2026-08-11 — none configured
  // anyway), so this is a small explicit rule, not a generic system:
  // tempering_cabinet_photo and final_tasks only make sense for a closing
  // shift, so they stay hidden unless shift = Late. Targeted by field name
  // (data-field, set in _buildGenericField/_buildPhotoField's WRAP), not by
  // group — the two fields sit in DIFFERENT field groups today
  // (tempering_cabinet_photo lives in "flavors_changes" alongside
  // cake_orders/flavors_changed, which stay visible either way; final_tasks
  // is alone in "end_of_day"), and Pods group membership is something
  // that's edited independently of this file, so hiding by group name
  // silently stopped covering tempering_cabinet_photo the moment it ended
  // up outside "end_of_day". Hiding by field name can't drift the same way.
  //
  // Hiding via the native `hidden` attribute (not a CSS-only class) matters
  // for tempering_cabinet_photo's required-ness — per the HTML5 spec, a
  // form control excluded from rendering by an ancestor's `display: none`
  // (which `hidden` applies by default) is also excluded from constraint
  // validation, so the required file input doesn't block submission while
  // hidden. _submit()'s own explicit photo check mirrors this by checking
  // the field's actual hidden state rather than a separate flag, so the two
  // can't drift apart.
  //
  // If another field ever needs the same treatment, add its name to
  // LATE_ONLY_FIELDS — not worth a generic engine for two instances.
  //
  // final_tasks is currently alone in its group ("End of day") — hiding
  // only the field would leave that group's <h3> heading showing above an
  // empty section. After toggling, also hide any group section left with
  // no visible .field children (and re-show one that gains its first
  // visible field back, e.g. switching from Late to Early to Late again).
  // Harmless no-op for "flavors_changes" (tempering_cabinet_photo's group),
  // which always keeps cake_orders/flavors_changed visible regardless.
  _wireConditionalGroups() {
    const LATE_ONLY_FIELDS = ['tempering_cabinet_photo', 'final_tasks'];
    const nodes = LATE_ONLY_FIELDS
      .map(name => this.ROOT.querySelector(`[data-field="${name}"]`))
      .filter(Boolean);
    if (!nodes.length || !this._shiftRadios) return;

    const groups = [...this.ROOT.querySelectorAll('.field-group')];

    const toggle = () => {
      const late = this._shiftRadios.no.checked;
      nodes.forEach(node => { node.hidden = !late; });

      groups.forEach(group => {
        const fields = [...group.querySelectorAll('.field')];
        group.hidden = fields.length > 0 && fields.every(f => f.hidden);
      });
    };
    this._shiftRadios.yes.addEventListener('change', toggle);
    this._shiftRadios.no.addEventListener('change', toggle);
    toggle(); // neither radio checked yet on first build — starts hidden
  }

  _buildBespokeField(field) {
    switch (field.name) {
      case 'tempering_cabinet_photo': return this._buildPhotoField(field);
      case 'flavors_changed':         return this._buildFlavorsChangedField(field);
      case 'supplies_low':            return this._buildSuppliesLowField(field);
      case 'cake_orders':             return this._buildCakeOrdersField(field);
      default: return null;
    }
  }

  // Generic renderer — covers every field with no entry in
  // BESPOKE_FIELD_NAMES, driven entirely by what the server already
  // resolved (type/label/description/required/options). Registers a value
  // getter in this._fieldGetters so _submit() can collect it without
  // needing to know the field ahead of time.
  _buildGenericField(field) {
    const el = this.el;
    let control;
    let skipOwnLabel = false;

    switch (field.type) {
      case 'paragraph':
        control = el('textarea',  { 
          attrs: { 
            ...(field.required ? { required: true } : {}),
            ...(field.description ? { placeholder: field.description } : {})
          }
        });
        this._fieldGetters[field.name] = () => control.value || '';
        break;

      case 'boolean':
        if (field.format === 'radio') {
          const groupName = `field-${field.name}`;
          const yes = el('input', { attrs: { type: 'radio', name: groupName, id: `${groupName}-yes`, value: '1' } });
          const no  = el('input', { attrs: { type: 'radio', name: groupName, id: `${groupName}-no`,  value: '0' } });
          const yesLabel = el('label', { classes: ['radio-option'] });
          yesLabel.append(yes, ` ${field.yesLabel}`);
          const noLabel = el('label', { classes: ['radio-option'] });
          noLabel.append(no, ` ${field.noLabel}`);
          control = el('div', { classes: ['radio-pair'] });
          control.append(yesLabel, noLabel);
          this._fieldGetters[field.name] = () => (yes.checked ? true : no.checked ? false : null);

          // 'shift' specifically drives the "End of day" group's
          // conditional visibility (see _wireConditionalGroups) — stashing
          // both radios here, keyed by field name, the same pattern
          // this.LOCATION_SELECT already uses for location.
          if (field.name === 'shift') this._shiftRadios = { yes, no };
        } else {
          const cb = el('input', { attrs: { type: 'checkbox' } });
          control = el('label', { classes: ['checkbox-field'] });
          control.append(cb, ` ${field.yesLabel || field.label}`);
          this._fieldGetters[field.name] = () => cb.checked;
          skipOwnLabel = true;
        }
        break;

      case 'pick': {
        const options = field.options ?? [];
        if (field.multi) {
          control = el('ul', { classes: ['generic-checklist'] });
          const boxes = [];
          for (const opt of options) {
            const id = `field-${field.name}-${this._slug(String(opt.id))}`;
            const cb = el('input', { attrs: { type: 'checkbox', id, value: opt.id } });
            boxes.push(cb);
            const label = el('label', { attrs: { for: id } });
            label.append(cb, ` ${opt.title}`);
            const li = el('li');
            li.append(label);
            control.append(li);
          }
          this._fieldGetters[field.name] = () =>
            boxes.filter(b => b.checked).map(b => (field.relatedPod ? Number(b.value) : b.value));
        } else {
          control = el('select', field.required ? { attrs: { required: true } } : {});
          control.append(el('option', { text: `— ${field.label} —`, attrs: { value: '' } }));
          for (const opt of options) control.append(el('option', { text: opt.title, attrs: { value: opt.id } }));
          if (field.name === 'location' && this.modelInstance.location) {
            control.value = String(this.modelInstance.location);
          }
          this._fieldGetters[field.name] = () => {
            if (!control.value) return field.relatedPod ? 0 : '';
            return field.relatedPod ? Number(control.value) : control.value;
          };
          if (field.name === 'location') this.LOCATION_SELECT = control;
        }
        break;
      }

      case 'number':
      case 'currency':
        control = el('input', { attrs: { type: 'number', step: '0.01', ...(field.required ? { required: true } : {}) } });
        this._fieldGetters[field.name] = () => (control.value ? Number(control.value) : 0);
        break;

      case 'date':
      case 'datetime':
        control = el('input', { attrs: { type: 'date', ...(field.required ? { required: true } : {}) } });
        this._fieldGetters[field.name] = () => control.value || '';
        break;

      case 'text':
      default:
        control = el('input', {
          attrs: {
            type: 'text', ...(field.required ? { required: true } : {}) ,
            ...(field.description ? { placeholder: field.description } : {}),
          }
        });
        // shift_lead ("Who are you?") defaults to whoever's actually logged
        // in — still a plain editable text field (someone else may be
        // filling this in on their behalf), just pre-filled instead of
        // blank. Same identity string as the hidden field at the top of the
        // form (see AUTH_USER in the constructor) — window.SCOOP.user.name
        // is the one client-side identity value every page already carries
        // (see enqueue.php's wp_localize_script, 'name' => user_nicename).
        if (field.name === 'shift_lead' && window.SCOOP?.user?.name) {
          control.value = window.SCOOP.user.name;
        }
        this._fieldGetters[field.name] = () => control.value || '';
        break;
    }

    const WRAP = el('div', { classes: ['field', `field-type-${field.type}`], data: { field: field.name } });
    if (!skipOwnLabel) WRAP.append(el('label', { classes: ['field-label'], text: field.label }));
    WRAP.append(control);
    if (field.description) WRAP.append(el('p', { text: field.description, classes: ['field-description'] }));

    return WRAP;
  }

  _slug(text) {
    return String(text).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  }

  // ── Tempering cabinet photo (required, single) ──────────────────────
  _buildPhotoField(field) {
    const el = this.el;
    const WRAP = el('div', { classes: ['field', 'field-type-file'], data: { field: field.name } });
    WRAP.append(el('label', { classes: ['field-label'], text: field.label }));
    
    // capture="environment" biases mobile/tablet browsers toward opening the
    // rear camera directly rather than a gallery picker — appropriate here
    // since this field is meant to capture what's actually in the cabinet
    // right now, not an old photo (see WHITEBOARD-INGESTION.md's "audit
    // signal" reasoning). Desktop browsers ignore the attribute and just
    // show a normal file picker. Some mobile browsers hide the "choose
    // existing photo" option entirely when this is set — accepted here as
    // the right default for a same-moment freshness check.
    this.PHOTO_INPUT = el('input', {
      attrs: { type: 'file', accept: 'image/*', capture: 'environment', ...(field.required ? { required: true } : {}) },
    });
    this.PHOTO_PREVIEW = el('div', { classes: ['photo-preview'] });
    this.PHOTO_INPUT.addEventListener('change', () => this._onPhotoSelected());

    WRAP.append(this.PHOTO_INPUT, this.PHOTO_PREVIEW);
    if (field.description) WRAP.append(el('p', { text: field.description, classes: ['field-description'] }));

    return WRAP;
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

  // ── Flavors changed out (multi-relationship, checkboxes) ────────────
  // Source list is today's slot.current_flavor, filtered to the selected
  // location and grouped by cabinet (see
  // ShiftReportGridModel.currentFlavorsByCabinet) — only flavors actually in
  // a cabinet slot right now are worth asking about, not the whole flavor
  // catalog. Rebuilt whenever the location changes (see the LOCATION_SELECT
  // listener in _buildForm), since a different location's cabinets hold
  // different flavors.
  _buildFlavorsChangedField(field) {
    const el = this.el;
    const WRAP = el('div', { classes: ['field', 'field-type-flavors-changed'] });
    WRAP.append(el('label', { classes: ['field-label'], text: field.label || 'Flavors changed out' }));
    this.FLAVORS_CHANGED_WRAP = el('div', { classes: ['flavors-changed-checklist'] });
    WRAP.append(this.FLAVORS_CHANGED_WRAP);
    return WRAP;
  }

  // Preserves already-checked flavors across a rebuild (location change, or
  // a background domain refresh) — a rebuild triggered by something other
  // than the user's own location change shouldn't discard their
  // in-progress picks.
  _renderFlavorsChangedChecklist() {
    if (!this.FLAVORS_CHANGED_WRAP) return;
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

  // ── Supplies low (multi-relationship, checkboxes grouped by category) ──
  // Source is the 'supply' pod's own 'group' field (see
  // WHITEBOARD-INGESTION.md — 83 items populated on local), grouped and
  // ordered via ShiftReportGridModel.supplyOptionsByGroup().
  _buildSuppliesLowField(field) {
    const el = this.el;
    const WRAP = el('div', { classes: ['field', 'field-type-supplies-low'] });
    WRAP.append(el('label', { classes: ['field-label'], text: field.label || 'Supplies: I noticed we are running out of' }));
    this.SUPPLIES_LOW_WRAP = el('div', { classes: ['supplies-low-checklist'] });
    WRAP.append(this.SUPPLIES_LOW_WRAP);
    return WRAP;
  }

  // No location-scoping needed (supply isn't location-specific), so unlike
  // flavors-changed this only needs to run once, from setDomain() — but
  // still preserves already-checked items the same way, in case a
  // background domain refresh lands mid-fill.
  _renderSuppliesLowChecklist() {
    if (!this.SUPPLIES_LOW_WRAP) return;
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

  // ── Cake orders — create new records inline, not a picker ───────────
  // cake_pie_flavor is a Pods custom Pick list on the cake_order pod
  // itself, not shift_report — this generic-fields fetch only covers
  // shift_report's own fields, so cake_pie_flavor stays a plain text input
  // as a stand-in until cake_order gets the same schema-driven treatment.
  _buildCakeOrdersField(field) {
    const el = this.el;
    const WRAP = el('div', { classes: ['field', 'field-type-cake-orders'] });
    WRAP.append(el('label', { classes: ['field-label'], text: field.label || '# of cake orders taken today' }));
    this.CAKE_ORDERS = el('div', { classes: ['cake-orders'] });
    this.ADD_CAKE_ORDER = el('button', {
      text: '+ Add cake order', classes: ['add-cake-order'], attrs: { type: 'button' },
    });
    this.ADD_CAKE_ORDER.addEventListener('click', () => this._addCakeOrderRow());
    WRAP.append(this.CAKE_ORDERS, this.ADD_CAKE_ORDER);
    return WRAP;
  }

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
      this._fieldLabeled('Order name', orderName),
      this._fieldLabeled('Cake/pie flavor', cakePieFlavor),
      this._fieldLabeled('Pickup date', pickupDate),
      this._fieldLabeled('Details', details),
      remove,
    );
    this.CAKE_ORDERS.append(LI);

    this._cakeOrderRows.push({ li: LI, orderName, cakePieFlavor, pickupDate, details });
  }

  _fieldLabeled(text, control) {
    const WRAP = this.el('label', { classes: ['field'] });
    WRAP.append(this.el('span', { text }), control);
    return WRAP;
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

  // ── Submit ────────────────────────────────────────────────────────
  async _submit() {
    // tempering_cabinet_photo is only required while it's actually visible
    // (see _wireConditionalGroups) — checking the field's own hidden state
    // here, rather than a separate tracked flag, means this can't drift out
    // of sync with what the user can actually see.
    const photoField = this.ROOT.querySelector('[data-field="tempering_cabinet_photo"]');
    const photoRequired = !photoField || !photoField.hidden;
    if (photoRequired && !this._photoId) {
      Toast.addMessage({ title: 'Photo required', message: 'Upload the tempering cabinet photo before submitting.' });
      return;
    }

    // Omit the key entirely when there's no photo (an early shift, field
    // hidden) rather than sending an explicit null — matches how a
    // never-filled-in field would arrive, not a distinct "clear it" signal.
    const payload = {};
    if (this._photoId) payload.tempering_cabinet_photo = this._photoId;
    for (const [name, getValue] of Object.entries(this._fieldGetters)) {
      payload[name] = getValue();
    }
    payload.flavors_changed = this._collectFlavorsChanged();
    payload.supplies_low = this._collectSuppliesLow();
    payload.new_cake_orders = this._collectNewCakeOrders();

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
    // Native reset() clears every checkbox/select/radio, but the flavors
    // checklist was built off whatever location was selected before
    // reset — rebuild it against the (possibly different) post-reset
    // location so it isn't showing a stale set of cabinets/flavors.
    this._renderFlavorsChangedChecklist();
  }
}
