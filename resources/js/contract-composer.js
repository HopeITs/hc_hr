/**
 * Contract "Composer" — the single-page screen behind both creating a contract
 * and editing one.
 *
 * Alpine owns the whole form while it is being filled in. Only three things
 * cross the wire: one renderless request per city change, one debounced
 * request for the leave-entitlement figure, and one request on submit.
 *
 * The two screens differ only in what Blade hands down: an edit seeds every
 * field from the contract it opened on, and appends a `termination` section
 * that a contract which does not exist yet cannot have.
 */

const CITY_LEVEL = '';

// Sunday(0)..Saturday(6) — hardcoded rather than read from the server's own
// (current-UI-locale-only) `dayNames`, because the printed-wording preview
// below shows English and Arabic side by side regardless of which locale the
// screen itself is in, same as the printed contract's own clause text is
// hardcoded bilingual strings rather than routed through the app's i18n.
const DAY_NAMES_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const DAY_NAMES_AR = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

const str = (value) => (value === null || value === undefined ? '' : String(value));

const clampIndex = (index, length) => (length === 0 ? -1 : Math.min(Math.max(index, 0), length - 1));

document.addEventListener('alpine:init', () => {
    /* ------------------------------------------------------------------ *
     * Combobox
     * ------------------------------------------------------------------ */
    window.Alpine.data('hcCombobox', (config) => ({
        open: false,
        query: '',
        active: 0,

        readOptions: config.readOptions,
        readValue: config.readValue,
        writeValue: config.writeValue,
        isDisabled: config.isDisabled,
        readError: config.readError ?? (() => false),
        clearable: config.clearable ?? false,
        placeholder: config.placeholder ?? '',

        get allOptions() {
            return this.readOptions() ?? [];
        },

        get value() {
            return str(this.readValue());
        },

        get hasError() {
            return Boolean(this.readError());
        },

        get filtered() {
            const needle = this.query.trim().toLocaleLowerCase();

            if (needle === '') {
                return this.allOptions;
            }

            return this.allOptions.filter((option) =>
                str(option.label).toLocaleLowerCase().includes(needle),
            );
        },

        get selectedLabel() {
            const match = this.allOptions.find((option) => str(option.value) === this.value);

            return match ? match.label : '';
        },

        get canClear() {
            return this.clearable && !this.open && this.value !== '';
        },

        isSelected(option) {
            return str(option.value) === this.value;
        },

        isFull(option) {
            return option.maxEmp !== undefined
                && option.maxEmp !== null
                && Number(option.existEmp) >= Number(option.maxEmp);
        },

        /** At most six segments, so a 40-seat position does not draw 40 bars. */
        seatBar(option) {
            const max = Number(option.maxEmp) || 0;
            const taken = Number(option.existEmp) || 0;

            if (max < 1) {
                return [];
            }

            const segments = Math.min(max, 6);
            const filled = Math.round((taken / max) * segments);

            return Array.from({ length: segments }, (_, i) => ({ i, taken: i < filled }));
        },

        initials(label) {
            return str(label)
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((word) => word.charAt(0))
                .join('')
                .toLocaleUpperCase();
        },

        firstSelectable() {
            return this.filtered.findIndex((option) => !this.isFull(option));
        },

        lastSelectable() {
            for (let i = this.filtered.length - 1; i >= 0; i -= 1) {
                if (!this.isFull(this.filtered[i])) {
                    return i;
                }
            }

            return -1;
        },

        openList() {
            if (this.isDisabled() || this.open) {
                return;
            }

            this.open = true;
            this.query = '';

            const selectedIndex = this.filtered.findIndex((option) => this.isSelected(option));
            this.active = selectedIndex >= 0 ? selectedIndex : this.firstSelectable();
        },

        toggle() {
            this.open ? this.close() : (this.$refs.input.focus(), this.openList());
        },

        close() {
            this.open = false;
            this.query = '';
        },

        /** Arrow keys skip over full positions rather than landing on them. */
        move(direction) {
            if (!this.open) {
                this.openList();

                return;
            }

            const options = this.filtered;

            if (options.length === 0) {
                return;
            }

            let next = clampIndex(this.active, options.length);

            for (let step = 0; step < options.length; step += 1) {
                next = (next + direction + options.length) % options.length;

                if (!this.isFull(options[next])) {
                    this.active = next;
                    this.scrollActiveIntoView();

                    return;
                }
            }
        },

        scrollActiveIntoView() {
            this.$nextTick(() => {
                this.$el.querySelector(`[aria-selected], #${CSS.escape(this.$refs.input.id)}-option-${this.active}`)
                    ?.scrollIntoView({ block: 'nearest' });
            });
        },

        choose(index) {
            const option = this.filtered[index];

            if (!option || this.isFull(option)) {
                return;
            }

            this.writeValue(str(option.value));
            this.close();
        },

        commitAndClose() {
            if (this.open && this.query.trim() !== '' && this.active >= 0) {
                this.choose(this.active);

                return;
            }

            this.close();
        },

        clear() {
            this.writeValue('');
            this.query = '';
        },
    }));

    /* ------------------------------------------------------------------ *
     * Composer
     * ------------------------------------------------------------------ */
    window.Alpine.data('contractComposer', (config) => ({
        // ---- configuration handed down from Blade
        typeDefaults: config.typeDefaults,
        labels: config.labels,
        locale: config.locale,
        cities: config.cities ?? [],
        terminationReasons: config.terminationReasons ?? [],
        canManageAutoRenew: Boolean(config.canManageAutoRenew),
        // True when the signed-in role may not see pay figures. The section is
        // rendered as a read-only notice in that case, the figures were never
        // sent down with the seed, and the server refuses to write them — so
        // the client-side pay rules below have to stand down too, or the
        // Save button would sit dead over an empty box nobody is allowed to
        // fill. See ContractForm::payIsHidden().
        payHidden: Boolean(config.payHidden),
        // Create renders four sections; edit adds `termination` on the end.
        sections: config.sections ?? ['assignment', 'contract', 'schedule', 'pay'],
        // Sunday(0)..Saturday(6), localized — built from the day range picker's
        // own options, reused here so the schedule summary can read "Sunday to
        // Thursday" instead of the day numbers.
        dayNames: config.dayNames ?? {},

        // ---- assignment
        // config.seed is present when this page opened on an existing contract
        // — a renewal or an edit — and fills every field below from it instead
        // of opening blank. Everything it sets stays user-editable afterwards.
        selected: {
            city: str(config.cityId),
            employee: str(config.seed?.employeeId),
            program: str(config.seed?.programId),
            position: str(config.seed?.positionId),
            contractType: str(config.seed?.contractTypeId),
        },
        options: { employees: [], programs: [], centers: [], positions: [] },

        // Set when the selected employee's most recent contract is a draft
        // (type = Draft, created from the employee-create screen's
        // position-reservation step) — submit() then upgrades that exact row
        // instead of inserting a new one. See checkForDraftContract(). Only
        // the create screen's component exposes the method this checks —
        // an edit is always about a contract that already exists.
        allowDraftUpgrade: config.allowDraftUpgrade ?? true,
        draftContractId: null,

        // ---- contract
        startDate: config.seed?.startDate ?? '',
        endDate: config.seed?.endDate ?? '',
        type: str(config.seed?.type ?? '1'),
        place: str(config.seed?.place ?? '1'),

        // ---- schedule
        // 1 = fixed, 2 = flexible (App\Enums\ScheduleMode). weeklyHours below
        // is a computed getter, not a field anyone writes to directly — see it
        // for how each mode arrives at a number.
        scheduleMode: str(config.seed?.scheduleMode ?? '1'),
        // A contract from before this field existed has neither a day range
        // nor flex hours to compute from — its own stored figure is the only
        // fallback until someone actually edits the schedule (see the
        // weeklyHours getter).
        initialWeeklyHours: config.seed ? Number(config.seed.weeklyHours) || 0 : 0,

        // -- fixed mode
        workHoursFrom: config.seed?.workHoursFrom ?? '09:00',
        workHoursTo: config.seed?.workHoursTo ?? '17:00',
        // Optional second daily shift, e.g. 9:00-13:00 and 17:00-21:00. Both
        // empty, or both set — see hasSecondShift()/toggleSecondShift().
        workHours2From: config.seed?.workHours2From ?? '',
        workHours2To: config.seed?.workHours2To ?? '',
        workDayFrom: config.seed?.workDayFrom != null ? str(config.seed.workDayFrom) : '',
        workDayTo: config.seed?.workDayTo != null ? str(config.seed.workDayTo) : '',
        // Whether the printed clause states each of these four pieces —
        // never edited directly, only ever toggled. A brand-new contract
        // (no seed) starts with all four on, since the standard clause
        // states all of them; an existing one keeps whatever it was saved
        // with, seed defaulting a missing key to false rather than true so
        // a contract from before these fields existed does not suddenly
        // start stating pieces nobody ever confirmed.
        showDaysPerWeek: config.seed ? Boolean(config.seed.showDaysPerWeek) : true,
        showHoursPerWeek: config.seed ? Boolean(config.seed.showHoursPerWeek) : true,
        showHoursPerDay: config.seed ? Boolean(config.seed.showHoursPerDay) : true,
        showDayRange: config.seed ? Boolean(config.seed.showDayRange) : true,

        // -- flexible mode — up to 5 independent, combinable boxes plus one
        // free-text fallback in both languages.
        flexDaysPerWeek: config.seed?.flexDaysPerWeek != null ? str(config.seed.flexDaysPerWeek) : '',
        flexHoursPerWeek: config.seed?.flexHoursPerWeek != null ? str(config.seed.flexHoursPerWeek) : '',
        flexHoursPerMonth: config.seed?.flexHoursPerMonth != null ? str(config.seed.flexHoursPerMonth) : '',
        flexVariesByRequirements: Boolean(config.seed?.flexVariesByRequirements),
        flexCoordinatedWithManager: Boolean(config.seed?.flexCoordinatedWithManager),
        flexCustomEn: config.seed?.flexCustomEn ?? '',
        flexCustomAr: config.seed?.flexCustomAr ?? '',
        // Unlike the numeric boxes above, free text has no non-empty "starting
        // value" to seed — so this is a real flag, not a value-presence
        // getter/setter, and initialize() clears the text when it goes off.
        flexCustomOn: Boolean(config.seed?.flexCustomEn || config.seed?.flexCustomAr),

        // ---- pay
        // 1 = fixed, 2 = flexible (App\Enums\PayMode).
        payMode: str(config.seed?.payMode ?? '1'),
        salary: config.seed?.salary ?? '',
        currency: str(config.seed?.currency ?? '1'),
        salaryPer: str(config.seed?.salaryPer ?? '1'),
        // Only meaningful when salaryPer is Session (App\Enums\SessionDuration).
        sessionDuration: config.seed?.sessionDuration != null ? str(config.seed.sessionDuration) : '',
        // -- Flexible mode: an unbounded "Add condition" repeater instead of
        // one flat figure, e.g. "if teaching 60h/month" => 200.
        payTiers: (config.seed?.payTiers ?? []).map((tier) => ({
            conditionEn: tier.conditionEn ?? '',
            conditionAr: tier.conditionAr ?? '',
            amount: tier.amount != null && tier.amount !== '' ? str(tier.amount) : '',
        })),

        // ---- termination (edit only; create does not render the section)
        terminatedAt: config.seed?.terminatedAt ?? '',
        terminationReasonId: str(config.seed?.terminationReasonId ?? ''),
        reasonOfTermination: config.seed?.reasonOfTermination ?? '',
        // A new contract starts with auto-renew on (G-B-10; ContractObserver
        // applies the same default); an edit or renewal shows what is saved.
        autoRenew: config.seed ? Boolean(config.seed.autoRenew) : true,

        // ---- ui
        // An edit opens with every section collapsed and summarised: there is
        // nothing to fill in there, only something to change.
        section: config.initialSection ?? 'assignment',
        loading: false,
        submitting: false,
        leaveDays: null,
        serverErrors: {},
        requestSequence: 0,
        leaveTimer: null,
        // Set while the composer rewrites its own selections, so the cascade
        // watchers do not mistake a programmatic tidy-up for a user choice.
        syncing: false,

        // =============================================================
        // Lifecycle
        // =============================================================

        initialize() {
            this.$watch('selected.city', () => this.syncing || this.loadCityOptions(true));
            this.$watch('selected.program', () => this.syncing || this.onProgramChange());
            this.$watch('selected.employee', () => this.syncing || this.onEmployeeChange());
            this.$watch('leaveInputs', () => this.scheduleLeavePreview());
            this.$watch('flexCustomOn', (on) => {
                if (!on) {
                    this.flexCustomEn = '';
                    this.flexCustomAr = '';
                }
            });

            if (this.selected.city) {
                this.loadCityOptions(false);
            } else {
                // No city yet (a fresh create, for a user who is not pinned
                // to one) — open the Employee field with the full list this
                // user may pick from anyway, rather than an empty one
                // waiting on a city choice nobody has a reason to make
                // first. See onEmployeeChange().
                this.loadEmployeeSearchOptions();
            }

            // A seeded screen — an edit, or a renewal — arrives with every
            // entitlement input already complete, so the watcher above has
            // nothing to react to. Ask once, up front.
            this.scheduleLeavePreview();

            // Kept so destroy() can remove it: this is a window listener, so
            // without cleanup every visit to the composer under wire:navigate
            // would stack another one that outlives its component.
            this.onComposerInvalid = (event) => {
                const sections = event.detail?.sections ?? [];

                if (sections.length > 0) {
                    this.section = sections[0];
                }

                this.submitting = false;
            };
            window.addEventListener('composer-invalid', this.onComposerInvalid);

            // B2: an error listed in the form's summary opens its section.
            this.onRevealField = (event) => {
                const name = event.detail?.section;

                if (this.sections.includes(name) && this.isUnlocked(name)) {
                    this.section = name;
                }
            };
            window.addEventListener('reveal-field', this.onRevealField);

            // B2: leaving with unsaved changes asks first. The baseline is
            // taken at the user's first touch, not here: seeding and the
            // cascades above keep rewriting the form while it loads, and none
            // of that is the user's work.
            this.unsavedBaseline = null;
            this.onFirstTouch = () => {
                if (this.unsavedBaseline === null) {
                    this.unsavedBaseline = JSON.stringify(this.payload());
                }
            };
            this.$el.addEventListener('pointerdown', this.onFirstTouch, true);
            this.$el.addEventListener('keydown', this.onFirstTouch, true);
            this.unsavedId = `composer:${this.$wire.$id}`;
            window.hcUnsaved?.register(this.unsavedId, {
                wireId: this.$wire.$id,
                // Not while sending: a successful save navigates away on its own.
                dirty: () => !this.submitting
                    && this.unsavedBaseline !== null
                    && JSON.stringify(this.payload()) !== this.unsavedBaseline,
            });
        },

        destroy() {
            window.removeEventListener('composer-invalid', this.onComposerInvalid);
            window.removeEventListener('reveal-field', this.onRevealField);
            window.hcUnsaved?.unregister(this.unsavedId);
            clearTimeout(this.leaveTimer);
        },

        // =============================================================
        // Section state machine
        // =============================================================

        /** Assignment (who/where) and Contract (type/term) merged into one section. */
        get assignmentValid() {
            return Boolean(this.selected.city && this.selected.employee
                && this.selected.program && this.selected.position
                && this.selected.contractType && this.type && this.place
                && this.startDate && this.endDate
                && this.endDate >= this.startDate && !this.endDateTooFar);
        },

        get scheduleValid() {
            if (this.scheduleMode === '1') {
                if (this.workDayFrom === '' || this.workDayTo === '' || Number(this.workDayTo) < Number(this.workDayFrom)) {
                    return false;
                }

                if (this.hasSecondShift() && !(this.workHours2From && this.workHours2To && this.workHours2To > this.workHours2From)) {
                    return false;
                }
            }

            // A service contract is the one category LeaveBalanceService
            // exempts from leave entitlement entirely, so "varies" /
            // "coordinated with manager" / free text with no explicit number
            // is fine there — everywhere else needs an actual weekly figure.
            if (this.selectedType?.category === 'service') {
                return true;
            }

            return Number(this.weeklyHours) >= 1;
        },

        /**
         * Why the section is not valid yet, in one short sentence — the
         * Continue button (x-contract.continue) only appears once it is, and
         * without this a blocked user has no way to tell what is missing,
         * since none of the boxes above are individually marked invalid.
         * Empty string once the section is actually valid.
         */
        get scheduleBlockedHint() {
            if (this.scheduleValid) {
                return '';
            }

            if (this.scheduleMode === '1') {
                if (this.workDayFrom === '' || this.workDayTo === '') {
                    return this.labels.scheduleHintDayRange;
                }

                if (this.hasSecondShift() && !(this.workHours2From && this.workHours2To && this.workHours2To > this.workHours2From)) {
                    return this.labels.scheduleHintSecondShift;
                }
            }

            return this.labels.scheduleHintWeeklyHours;
        },

        get payValid() {
            if (this.payHidden) {
                return true;
            }

            if (this.payMode === '2') {
                return this.payTiers.length >= 1 && this.payTiers.every((tier) => (
                    tier.conditionEn.trim() !== ''
                    && tier.conditionAr.trim() !== ''
                    && tier.amount !== ''
                    && Number.isFinite(Number(tier.amount))
                    && Number(tier.amount) >= 0
                ));
            }

            const sessionValid = this.salaryPer !== '3' || this.sessionDuration !== '';

            return this.salary !== '' && Number(this.salary) >= 0 && Number.isFinite(Number(this.salary)) && sessionValid;
        },

        /**
         * What is wrong with the salary box itself, in one short sentence.
         * Mirrors payValid's own numeric rule on purpose: when the two disagree,
         * Create goes dead with nothing marked, which is the exact failure this
         * exists to prevent. An empty box is not an error — nothing has been
         * typed yet, and payBlockedHint speaks for that case instead.
         */
        get salaryError() {
            if (this.salary === '') {
                return '';
            }

            if (!Number.isFinite(Number(this.salary))) {
                return this.labels.payHintSalaryNumber;
            }

            return Number(this.salary) < 0 ? this.labels.payHintSalaryNegative : '';
        },

        /** The same rule, for one flexible-mode tier's amount. */
        tierAmountError(tier) {
            if (tier.amount === '') {
                return '';
            }

            return Number.isFinite(Number(tier.amount)) && Number(tier.amount) >= 0
                ? ''
                : this.labels.payHintAmountNegative;
        },

        /**
         * Why the section is not valid yet — the counterpart to
         * scheduleBlockedHint, and the load-bearing one: pay is the last
         * required section, so there is no Continue button beneath it to
         * withhold. Without this, an unfinished pay section shows up only as a
         * greyed-out Create button on the far side of the screen.
         */
        get payBlockedHint() {
            if (this.payValid) {
                return '';
            }

            if (this.payMode === '2') {
                return this.payTiers.length === 0
                    ? this.labels.payHintNoTiers
                    : this.labels.payHintTiersIncomplete;
            }

            if (this.salary === '') {
                return this.labels.payHintSalaryRequired;
            }

            // A bad figure already marks and explains itself at the box. Saying
            // it again down here would print one sentence twice, inches apart.
            return this.salaryError ? '' : this.labels.payHintSessionDuration;
        },

        addPayTier() {
            this.payTiers.push({ conditionEn: '', conditionAr: '', amount: '' });
        },

        removePayTier(index) {
            this.payTiers.splice(index, 1);
        },

        isValid(name) {
            return {
                assignment: this.assignmentValid,
                schedule: this.scheduleValid,
                pay: this.payValid,
                // Optional by nature: a contract is complete whether or not it
                // ended early, so this section never locks the ones after it.
                termination: true,
            }[name] ?? false;
        },

        /** The sections that must be filled in before the form can be sent. */
        get requiredSections() {
            return this.sections.filter((name) => name !== 'termination');
        },

        isLastSection(name) {
            return this.sections[this.sections.length - 1] === name;
        },

        /** The first earlier section that is not yet satisfied, or null. */
        blockedBy(name) {
            const index = this.sections.indexOf(name);

            for (let i = 0; i < index; i += 1) {
                if (!this.isValid(this.sections[i])) {
                    return this.sections[i];
                }
            }

            return null;
        },

        isUnlocked(name) {
            return this.blockedBy(name) === null;
        },

        stateOf(name) {
            if (this.section === name) {
                return 'open';
            }

            if (!this.isUnlocked(name)) {
                return 'locked';
            }

            return this.isValid(name) ? 'done' : 'idle';
        },

        openSection(name) {
            if (!this.isUnlocked(name)) {
                return;
            }

            this.section = this.section === name ? '' : name;
        },

        nextSection() {
            const next = this.sections[this.sections.indexOf(this.section) + 1];

            if (next && this.isUnlocked(next)) {
                this.section = next;
            }
        },

        get completedCount() {
            return this.requiredSections.filter((name) => this.isValid(name)).length;
        },

        get canSubmit() {
            return this.completedCount === this.requiredSections.length
                && !this.submitting && !this.loading;
        },

        // =============================================================
        // Assignment cascade
        // =============================================================

        async loadCityOptions(clearDownstream = true) {
            const cityId = Number(this.selected.city);
            const request = (this.requestSequence += 1);

            if (clearDownstream) {
                await this.withSync(() => this.resetDownstream());
            }

            if (!Number.isInteger(cityId) || cityId < 1) {
                this.options = { employees: [], programs: [], centers: [], positions: [] };

                return;
            }

            this.loading = true;

            try {
                const payload = await this.$wire.locationOptions(cityId);

                if (request !== this.requestSequence) {
                    return;
                }

                this.options = {
                    employees: payload.employees ?? [],
                    programs: payload.programs ?? [],
                    centers: payload.centers ?? [],
                    positions: payload.positions ?? [],
                };

                await this.withSync(() => this.dropUnavailableSelections());
            } finally {
                if (request === this.requestSequence) {
                    this.loading = false;
                }
            }
        },

        async withSync(mutate) {
            this.syncing = true;

            try {
                mutate();
            } finally {
                await this.$nextTick();
                this.syncing = false;
            }
        },

        /**
         * Employee is deliberately NOT cleared here. Since the Assignment step
         * became employee-first, city is derived FROM the employee, not the
         * other way around — a manual city override (e.g. switching to the
         * nationwide/headquarters scope, which accepts an employee from any
         * city) must not discard an already-made employee choice. Program
         * and position genuinely are city-scoped lists with no such
         * exemption, so those still reset up front; dropUnavailableSelections()
         * below is what decides the employee's fate once the new city's real
         * employee list has actually loaded.
         */
        resetDownstream() {
            this.selected.program = '';
            this.selected.position = '';
        },

        dropUnavailableSelections() {
            const has = (list, value) => value === '' || list.some((o) => str(o.value) === value);

            if (!has(this.options.employees, this.selected.employee)) {
                this.selected.employee = '';
            }

            if (!has(this.options.programs, this.selected.program)) {
                this.selected.program = '';
                this.selected.position = '';

                return;
            }

            if (!has(this.positionOptions, this.selected.position)) {
                this.selected.position = '';
            }
        },

        onCityChange() {
            this.loadCityOptions(true);
        },

        /**
         * Loads the full employee list this user may pick from, with no city
         * chosen yet — how the Assignment step opens for anyone not already
         * pinned to one city. Picking a name here is what determines the
         * city (see onEmployeeChange()), not the other way around.
         */
        async loadEmployeeSearchOptions() {
            this.loading = true;

            try {
                const employees = await this.$wire.employeeSearchOptions();

                // A city may already have been picked (or derived) by the
                // time this resolves — never clobber a real, city-scoped
                // list with the wider one it started from.
                if (!this.selected.city) {
                    this.options.employees = employees ?? [];
                }
            } finally {
                this.loading = false;
            }
        },

        /**
         * Runs whenever the chosen employee changes: derives their city (so
         * picking someone is enough on its own — no separate city step), then
         * checks for a draft contract, which may refine city/program/position
         * further from data more specific than the employee's own residence.
         */
        async onEmployeeChange() {
            const employeeId = Number(this.selected.employee);

            if (!Number.isInteger(employeeId) || employeeId < 1) {
                this.draftContractId = null;

                return;
            }

            const employee = this.options.employees
                .find((candidate) => str(candidate.value) === this.selected.employee);
            const employeeCityId = employee?.cityId != null ? str(employee.cityId) : '';

            if (employeeCityId && employeeCityId !== this.selected.city) {
                await this.withSync(() => { this.selected.city = employeeCityId; });
                await this.loadCityOptions(false);
            }

            await this.checkForDraftContract();
        },

        /**
         * Auto-fills city/program/center/position from the selected employee's
         * draft contract, if their most recently created contract is one —
         * refining what onEmployeeChange() already set from their residence
         * city, since the draft's own position is the more specific answer
         * when the two disagree (e.g. a temporary assignment elsewhere).
         * Fields set here stay user-editable afterwards, same as a renewal's
         * seed — this only saves re-entering what the employee-create screen
         * already captured, it does not lock anything in.
         */
        async checkForDraftContract() {
            const employeeId = Number(this.selected.employee);

            if (!this.allowDraftUpgrade || !Number.isInteger(employeeId) || employeeId < 1) {
                this.draftContractId = null;

                return;
            }

            const draft = await this.$wire.draftContractFor(employeeId);

            if (!draft) {
                this.draftContractId = null;

                return;
            }

            this.draftContractId = draft.draftContractId;

            const draftCityId = draft.cityId != null ? str(draft.cityId) : '';

            if (draftCityId && draftCityId !== this.selected.city) {
                await this.withSync(() => { this.selected.city = draftCityId; });
                await this.loadCityOptions(false);
            }

            await this.withSync(() => {
                this.selected.program = str(draft.programId ?? '');
                this.selected.position = str(draft.positionId ?? '');
            });
        },

        onProgramChange() {
            this.selected.position = '';
        },

        get programPositions() {
            if (!this.selected.program) {
                return [];
            }

            return this.options.positions
                .filter((position) => str(position.programId) === this.selected.program);
        },

        get positionOptions() {
            const centerName = (id) => this.options.centers
                .find((center) => str(center.value) === str(id))?.label ?? '';

            return this.programPositions
                .map((position) => ({
                    ...position,
                    centerLabel: str(position.centerId) === ''
                        ? this.labels.cityLevel
                        : centerName(position.centerId),
                }));
        },

        get selectedEmployee() {
            return this.options.employees
                .find((employee) => str(employee.value) === this.selected.employee) ?? null;
        },

        get selectedPosition() {
            return this.options.positions
                .find((position) => str(position.value) === this.selected.position) ?? null;
        },

        get selectedProgramLabel() {
            return this.options.programs
                .find((program) => str(program.value) === this.selected.program)?.label ?? '';
        },

        get selectedCenterLabel() {
            const position = this.selectedPosition;

            if (!position) {
                return '';
            }

            if (str(position.centerId) === '') {
                return this.labels.cityLevel;
            }

            return this.options.centers
                .find((center) => str(center.value) === str(position.centerId))?.label ?? '';
        },

        get capacity() {
            const position = this.selectedPosition;

            if (!position || !position.maxEmp) {
                return null;
            }

            const max = Number(position.maxEmp);
            const taken = Number(position.existEmp);

            return { taken, max, left: Math.max(0, max - taken), full: taken >= max };
        },

        // =============================================================
        // Contract
        // =============================================================

        get selectedType() {
            return this.typeDefaults[this.selected.contractType] ?? null;
        },

        chooseType(id) {
            this.selected.contractType = str(id);

            const defaults = this.typeDefaults[str(id)];

            if (defaults) {
                // G-B-8: a date the user typed is theirs; the type only fills
                // in what is still the default.
                const keepStart = this.datesTyped.start && Boolean(this.startDate);
                const keepEnd = this.datesTyped.end && Boolean(this.endDate);

                if (!keepStart) {
                    this.startDate = defaults.start;
                }

                if (!keepEnd) {
                    this.endDate = keepStart ? this.defaultEndFrom(this.startDate, defaults) : defaults.end;
                }

                if (!keepStart || !keepEnd) {
                    this.flashDates();
                }

                // Full/Part time only make sense for a volunteer or trial
                // contract; Freelance only for a service one — switching
                // category must not leave a now-invalid type selected.
                if (defaults.category === 'service') {
                    this.type = '3'; // Freelance
                } else if (this.type === '3') {
                    this.type = '1'; // Full Time
                }
            }
        },

        // Set only by the user's own input in a date field, never by a type
        // change or a seed.
        datesTyped: { start: false, end: false },

        /**
         * The type's end date for a start the user chose, by the rules of
         * ContractForm::updatedContractTypeId(): a trial runs three months
         * (no overflow: 31 Jan → 30 Apr); any other type keeps its default end
         * while that still follows the start, otherwise runs a full year.
         */
        defaultEndFrom(start, defaults) {
            const [year, month, day] = start.split('-').map(Number);
            const iso = (y, m, d) => [y, String(m).padStart(2, '0'), String(d).padStart(2, '0')].join('-');

            if (defaults.category === 'trial') {
                const target = new Date(Date.UTC(year, month - 1 + 3, 1));
                const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();

                return iso(target.getUTCFullYear(), target.getUTCMonth() + 1, Math.min(day, lastDay));
            }

            if (defaults.end && defaults.end > start) {
                return defaults.end;
            }

            return new Date(Date.UTC(year + 1, month - 1, day - 1)).toISOString().slice(0, 10);
        },

        flashDates() {
            this.datesJustSet = true;
            setTimeout(() => { this.datesJustSet = false; }, 700);
        },
        datesJustSet: false,

        /** Mirrors validateTerms(): end_date must be within a year of the start. */
        get maxEndDate() {
            if (!this.startDate) {
                return '';
            }

            const date = new Date(`${this.startDate}T00:00:00`);
            date.setFullYear(date.getFullYear() + 1);

            return date.toISOString().slice(0, 10);
        },

        get endDateTooFar() {
            return Boolean(this.startDate && this.endDate && this.maxEndDate
                && this.endDate > this.maxEndDate);
        },

        /**
         * Months to one decimal place, mirroring Contract::getDurationAttribute()
         * — the same figure the leave calculation runs on.
         *
         * Reporting "3 months · 30 days" for 1 Sep → 31 Dec was accurate but read
         * as a shorter contract than it is, and disagreed with the number the
         * server would store for the very same dates.
         */
        get durationLabel() {
            if (!this.startDate || !this.endDate || this.endDate < this.startDate) {
                return '';
            }

            const start = new Date(`${this.startDate}T00:00:00`);
            const end = new Date(`${this.endDate}T00:00:00`);

            let months = (end.getFullYear() - start.getFullYear()) * 12
                + (end.getMonth() - start.getMonth());

            const anchor = new Date(start);
            anchor.setMonth(anchor.getMonth() + months);

            if (anchor > end) {
                months -= 1;
                anchor.setMonth(anchor.getMonth() - 1);
            }

            if (months < 0) {
                return '';
            }

            // How far into the following month the end date falls.
            const nextAnchor = new Date(anchor);
            nextAnchor.setMonth(nextAnchor.getMonth() + 1);

            const total = Math.round((months + (end - anchor) / (nextAnchor - anchor)) * 10) / 10;

            if (total < 1) {
                const days = Math.max(0, Math.round((end - start) / 86400000));

                return this.countLabel(this.labels.daysCount, this.labels.daysDecimal, days);
            }

            return this.countLabel(this.labels.monthsCount, this.labels.monthsDecimal, total);
        },

        // =============================================================
        // Schedule
        // =============================================================

        /** Hours between two 'HH:MM' strings, or 0 if either is missing/inverted. */
        hoursBetween(from, to) {
            if (!from || !to) {
                return 0;
            }

            const [fromHours, fromMinutes] = from.split(':').map(Number);
            const [toHours, toMinutes] = to.split(':').map(Number);
            const minutes = (toHours * 60 + toMinutes) - (fromHours * 60 + fromMinutes);

            return minutes > 0 ? minutes / 60 : 0;
        },

        hasSecondShift() {
            return Boolean(this.workHours2From || this.workHours2To);
        },

        /**
         * 'HH:MM' (24h, straight from the time input) -> '9:00 AM', or
         * '9:00 ص' / '5:00 م' for the Arabic wording (A4) — matches
         * the format the actual print templates already use for office
         * hours (see page2.blade.php's `g:i A`), so the preview reads the
         * same way the printed contract will.
         */
        to12Hour(value, arabic = false) {
            if (!value) {
                return '';
            }

            const [hoursStr, minutes] = value.split(':');
            let hours = Number(hoursStr);
            const suffix = arabic ? (hours >= 12 ? 'م' : 'ص') : (hours >= 12 ? 'PM' : 'AM');

            hours %= 12;

            if (hours === 0) {
                hours = 12;
            }

            return `${hours}:${minutes} ${suffix}`;
        },

        toggleSecondShift(checked) {
            if (!checked) {
                this.workHours2From = '';
                this.workHours2To = '';

                return;
            }

            // A sensible starting point for the second shift, distinct from
            // the first — the user is expected to adjust it.
            this.workHours2From = this.workHours2From || '17:00';
            this.workHours2To = this.workHours2To || '21:00';
        },

        /** Whole days in the fixed day range (no week-wrap: to must be >= from). */
        get fixedDaysPerWeek() {
            const from = Number(this.workDayFrom);
            const to = Number(this.workDayTo);

            if (this.workDayFrom === '' || this.workDayTo === '' || to < from) {
                return 0;
            }

            return to - from + 1;
        },

        /** Hours in one working day under fixed mode — both shifts combined. */
        get fixedHoursPerDay() {
            return this.hoursBetween(this.workHoursFrom, this.workHoursTo)
                + this.hoursBetween(this.workHours2From, this.workHours2To);
        },

        /**
         * "Sunday to Thursday" — built from the day range, in the viewer's own
         * locale, rather than typed in as free text (superseded: work days
         * used to be a separate bilingual text box, replaced by this once the
         * structured day range covered the same ground).
         */
        get fixedDayRangeLabel() {
            if (this.workDayFrom === '' || this.workDayTo === '') {
                return '';
            }

            const from = this.dayNames[this.workDayFrom] ?? '';

            if (this.workDayFrom === this.workDayTo) {
                return from;
            }

            const to = this.dayNames[this.workDayTo] ?? '';

            return this.fill(this.labels.dayRange, { from, to });
        },

        /**
         * The single source of truth for weekly_hours — LeaveBalanceService's
         * real entitlement engine — computed, never typed in directly. Fixed
         * mode derives it from the day/hour ranges above; flexible mode takes
         * whatever was typed into the "hours/week" box (0 if that box is off,
         * which is only valid for a service-category contract — scheduleValid
         * enforces that, not this getter).
         *
         * A contract seeded from one that predates the day-range fields (no
         * work_day_from/to, no flex hours) has nothing to compute from yet —
         * falling straight to 0 would silently zero out a real, already-valid
         * figure the moment its renewal or edit screen opens, before anyone
         * touched anything. Its own stored weekly_hours is the fallback until
         * the day range (or flex box) is actually filled in.
         */
        get weeklyHours() {
            if (this.scheduleMode === '1') {
                if (this.workDayFrom === '' && this.workDayTo === '') {
                    return this.initialWeeklyHours;
                }

                return Math.round(this.fixedDaysPerWeek * this.fixedHoursPerDay * 100) / 100;
            }

            if (this.flexHoursPerWeek === '') {
                return this.initialWeeklyHours;
            }

            return Number(this.flexHoursPerWeek) || 0;
        },

        /** "N days/week" — shown next to its own checkbox, fixed mode only. */
        get fixedDaysPerWeekLabel() {
            return this.fill(this.choice(this.labels.daysPerWeek, this.fixedDaysPerWeek), { count: this.fixedDaysPerWeek });
        },

        /** "M hours/week" — shown next to its own checkbox, fixed mode only. */
        get fixedHoursPerWeekLabel() {
            return this.fill(this.choice(this.labels.hoursPerWeek, this.weeklyHours), { count: this.weeklyHours });
        },

        /** "K hours/day" — shown next to its own checkbox, fixed mode only. */
        get fixedHoursPerDayLabel() {
            return this.fill(this.choice(this.labels.hoursPerDay, this.fixedHoursPerDay), { count: this.fixedHoursPerDay });
        },

        /** The always-visible read-only summary, e.g. "40 h/week" — both modes. */
        get weeklyHoursSummary() {
            return this.fill(this.labels.weeklyHoursValue, { count: this.weeklyHours });
        },

        /**
         * Each numeric flex box is really a checkbox with a number attached:
         * the getter/setter pair lets its own checkbox's x-model read "is a
         * value set" and write "clear it" / "give it a starting value" in one
         * place, instead of tracking a second on/off flag that could drift
         * out of sync with whether the field is actually empty.
         */
        get flexDaysPerWeekOn() {
            return this.flexDaysPerWeek !== '';
        },
        set flexDaysPerWeekOn(checked) {
            this.flexDaysPerWeek = checked ? (this.flexDaysPerWeek || '5') : '';
        },

        get flexHoursPerWeekOn() {
            return this.flexHoursPerWeek !== '';
        },
        set flexHoursPerWeekOn(checked) {
            this.flexHoursPerWeek = checked ? (this.flexHoursPerWeek || '40') : '';
        },

        get flexHoursPerMonthOn() {
            return this.flexHoursPerMonth !== '';
        },
        set flexHoursPerMonthOn(checked) {
            this.flexHoursPerMonth = checked ? (this.flexHoursPerMonth || '60') : '';
        },


        // =============================================================
        // Termination — rendered by the edit screen only.
        // =============================================================

        get isTerminated() {
            return Boolean(this.terminatedAt || this.terminationReasonId);
        },

        get terminationReasonLabel() {
            return this.terminationReasons
                .find((reason) => str(reason.value) === this.terminationReasonId)?.label ?? '';
        },

        clearTermination() {
            this.terminatedAt = '';
            this.terminationReasonId = '';
            this.reasonOfTermination = '';
        },

        // =============================================================
        // Pay
        // =============================================================

        get currencyLabel() {
            return { 0: this.labels.unknownCurrency, 1: 'SYP', 2: 'USD', 3: 'EUR' }[this.currency] ?? '';
        },

        get salaryPerLabel() {
            return {
                1: this.labels.perMonth,
                2: this.labels.perHour,
                3: this.labels.perSession,
            }[this.salaryPer] ?? '';
        },

        formatNumber(value) {
            const number = Number(value);

            if (!Number.isFinite(number)) {
                return '';
            }

            return new Intl.NumberFormat(this.locale === 'ar' ? 'ar-EG' : 'en-US').format(number);
        },

        get salaryEcho() {
            // A figure the form will not accept is never echoed back formatted,
            // and never annualised: "-500 · -6,000 per year" reads as a real
            // salary that was understood, when it is the thing blocking submit.
            if (this.salary === '' || this.salaryError) {
                return '';
            }

            const base = `${this.formatNumber(this.salary)} ${this.currencyLabel} ${this.salaryPerLabel}`;

            if (this.salaryPer !== '1') {
                return base;
            }

            const yearly = this.formatNumber(Number(this.salary) * 12);

            return `${base} · ${yearly} ${this.currencyLabel} ${this.labels.perYear}`;
        },

        // =============================================================
        // Display
        // =============================================================

        /** Substitutes Laravel's :placeholders, so no copy is assembled in PHP. */
        fill(template, values) {
            return Object.entries(values).reduce(
                (text, [key, value]) => text.replaceAll(`:${key}`, value),
                str(template),
            );
        },

        /**
         * Picks the segment of a Laravel pipe-plural string ("{1} :count day|
         * [2,*] :count days") that matches `count` — the same {n} / [from,to]
         * conditions trans_choice() reads, so Arabic's six forms come straight
         * from the lang file. A string without conditions is returned as is.
         */
        choice(template, count) {
            const segments = str(template).split('|');
            const number = Number(count);
            const condition = /^\s*(?:\{\s*([^}]*?)\s*\}|\[\s*([^,\]]*?)\s*,\s*([^\]]*?)\s*\])\s*([\s\S]*)$/;

            for (const segment of segments) {
                const match = segment.match(condition);

                if (!match) {
                    continue;
                }

                const [, exact, from, to, text] = match;

                if (exact !== undefined) {
                    if (number === Number(exact)) {
                        return text.trim();
                    }

                    continue;
                }

                const low = from === '*' ? -Infinity : Number(from);
                const high = to === '*' ? Infinity : Number(to);

                if (number >= low && number <= high) {
                    return text.trim();
                }
            }

            return segments[segments.length - 1].replace(condition, '$4').trim();
        },

        /**
         * "3 months" / "٣ أشهر": whole numbers take their plural form, a
         * fraction (2.5) the plain one. `display` is what replaces :count, so
         * a figure can be locale-formatted without changing which form is picked.
         */
        countLabel(template, decimalTemplate, value, display = value) {
            const number = Number(value);
            const chosen = Number.isInteger(number) ? this.choice(template, number) : str(decimalTemplate);

            return this.fill(chosen, { count: display });
        },

        /**
         * Wraps a value in Unicode first-strong isolates — the plain-text
         * equivalent of <bdi>, for strings that end up in x-text — so a time
         * range or a Latin name keeps its own order inside Arabic text.
         */
        isolate(value) {
            return value === '' || value === null || value === undefined ? '' : `⁨${value}⁩`;
        },

        formatDate(value) {
            if (!value) {
                return '';
            }

            return new Intl.DateTimeFormat(this.locale === 'ar' ? 'ar-EG' : 'en-GB', {
                day: 'numeric', month: 'short', year: 'numeric',
            }).format(new Date(`${value}T00:00:00`));
        },

        get cityName() {
            return this.cities.find((city) => str(city.value) === this.selected.city)?.label ?? '';
        },

        lockedHintFor(name) {
            const blocker = this.blockedBy(name);

            return blocker
                ? this.fill(this.labels.lockedBecause, { section: this.labels.sections[blocker] })
                : '';
        },

        get progressLabel() {
            return this.fill(this.labels.progress, { done: this.completedCount, total: this.requiredSections.length });
        },

        get duplicateContractNotice() {
            const endsAt = this.selectedEmployee?.activeContractEndsAt;

            return endsAt ? this.fill(this.labels.duplicate, { date: this.formatDate(endsAt) }) : '';
        },

        /** Non-empty once checkForDraftContract() finds this employee already has a reserved position. */
        get draftContractNotice() {
            return this.draftContractId ? this.labels.draftFound : '';
        },

        get capacityBar() {
            const capacity = this.capacity;

            if (!capacity) {
                return [];
            }

            const segments = Math.min(capacity.max, 6);
            const filled = Math.round((capacity.taken / capacity.max) * segments);

            return Array.from({ length: segments }, (_, i) => ({ i, taken: i < filled }));
        },

        get capacityLabel() {
            const capacity = this.capacity;

            if (!capacity) {
                return '';
            }

            return capacity.left === 0
                ? this.fill(this.labels.noSeatsLeft, { taken: capacity.taken, max: capacity.max })
                : this.fill(this.labels.seatsLeft, {
                    taken: capacity.taken, max: capacity.max, left: capacity.left,
                });
        },

        // ---- collapsed section summaries -------------------------------

        get assignmentSummary() {
            const period = this.startDate && this.endDate
                ? `${this.isolate(this.formatDate(this.startDate))} ${this.labels.periodArrow} ${this.isolate(this.formatDate(this.endDate))}`
                : '';

            return [this.isolate(this.selectedEmployee?.label), this.isolate(this.selectedPosition?.label), this.selectedType?.name, period]
                .filter(Boolean).join(' · ');
        },

        /**
         * How the "Volunteer Work Hours" clause would read on the printed
         * contract, in each language — built from whichever fields and
         * checkboxes are currently set, so "what will this actually print?"
         * does not require creating the contract and opening the PDF.
         * Deliberately plain (fragments joined into sentences, not fully
         * polished legal prose): this is a live preview, not the Phase 5
         * print template itself, which is the actual source of truth.
         */
        schedulePreview(lang) {
            const isAr = lang === 'ar';
            const dayNames = isAr ? DAY_NAMES_AR : DAY_NAMES_EN;
            const emptyText = isAr ? 'لم يتم تحديد تفاصيل الدوام بعد.' : 'No schedule details selected yet.';

            if (this.scheduleMode === '1') {
                if (this.workDayFrom === '' || this.workDayTo === '') {
                    return emptyText;
                }

                const sentences = [];

                // Each checkbox controls whether its own piece is stated at
                // all, rather than the number always appearing with the
                // checkbox only adding a second, redundant "states X"
                // sentence after it (the previous design).
                const clauses = [];

                if (this.showHoursPerWeek) {
                    clauses.push(isAr
                        ? `${this.weeklyHours} ساعة عمل أسبوعيًا`
                        : `${this.weeklyHours} working hours per week`);
                }

                if (this.showHoursPerDay) {
                    clauses.push(isAr
                        ? `${this.fixedHoursPerDay} ساعات يوميًا`
                        : `${this.fixedHoursPerDay} hours per day`);
                }

                if (this.showDaysPerWeek) {
                    clauses.push(isAr
                        ? `${this.fixedDaysPerWeek} أيام أسبوعيًا`
                        : `${this.fixedDaysPerWeek} days per week`);
                }

                if (clauses.length > 0) {
                    sentences.push(clauses.join(isAr ? '، ' : ', ') + '.');
                }

                let timePart = isAr
                    ? `من الساعة ${this.to12Hour(this.workHoursFrom, true)} حتى الساعة ${this.to12Hour(this.workHoursTo, true)}`
                    : `from ${this.to12Hour(this.workHoursFrom)} to ${this.to12Hour(this.workHoursTo)}`;

                if (this.hasSecondShift()) {
                    timePart += isAr
                        ? ` ومن الساعة ${this.to12Hour(this.workHours2From, true)} حتى الساعة ${this.to12Hour(this.workHours2To, true)}`
                        : ` and from ${this.to12Hour(this.workHours2From)} to ${this.to12Hour(this.workHours2To)}`;
                }

                let officeHours = isAr ? 'ساعات الدوام الرسمية هي' : 'Standard office hours are';

                if (this.showDayRange) {
                    const from = dayNames[Number(this.workDayFrom)] ?? '';
                    const to = dayNames[Number(this.workDayTo)] ?? '';
                    const days = from === to ? from : (isAr ? `من ${from} إلى ${to}` : `${from} to ${to}`);

                    // Arabic joins "و" straight onto the next word ("ومن")
                    // rather than as its own token — matching the second
                    // shift join just above.
                    officeHours += isAr ? ` ${days} و${timePart}` : ` ${days} and ${timePart}`;
                } else {
                    officeHours += ` ${timePart}`;
                }

                sentences.push(officeHours + '.');

                return sentences.join(' ');
            }

            // Flexible mode: assemble whichever descriptive fragments are checked.
            const fragments = [];

            if (this.flexHoursPerWeekOn && this.flexHoursPerWeek !== '') {
                fragments.push(isAr ? `${this.flexHoursPerWeek} ساعة أسبوعيًا` : `${this.flexHoursPerWeek} hours per week`);
            }

            if (this.flexDaysPerWeekOn && this.flexDaysPerWeek !== '') {
                // Arabic counts 3-10 take the plural (أيام); 1-2 stay singular.
                fragments.push(isAr
                    ? `${this.flexDaysPerWeek} ${Number(this.flexDaysPerWeek) >= 3 ? 'أيام' : 'يوم'} أسبوعيًا`
                    : `${this.flexDaysPerWeek} days per week`);
            }

            if (this.flexHoursPerMonthOn && this.flexHoursPerMonth !== '') {
                fragments.push(isAr ? `${this.flexHoursPerMonth} ساعة شهريًا` : `${this.flexHoursPerMonth} hours per month`);
            }

            if (this.flexVariesByRequirements) {
                fragments.push(isAr ? 'الساعات تتغير بحسب متطلبات العمل' : 'hours vary according to the requirements of the job');
            }

            if (this.flexCoordinatedWithManager) {
                fragments.push(isAr
                    ? 'أيام وساعات العمل تُحدَّد بالتنسيق مع المدير المباشر'
                    : 'working days and hours are coordinated with the direct manager');
            }

            if (this.flexCustomOn) {
                const custom = (isAr ? this.flexCustomAr : this.flexCustomEn).trim();

                if (custom) {
                    fragments.push(custom);
                }
            }

            if (!fragments.length) {
                return emptyText;
            }

            const joined = fragments.join(isAr ? '، ' : ', ');

            return joined.charAt(0).toUpperCase() + joined.slice(1) + '.';
        },

        get schedulePreviewEn() {
            return this.schedulePreview('en');
        },

        get schedulePreviewAr() {
            return this.schedulePreview('ar');
        },

        get scheduleSummary() {
            const hours = this.weeklyHours > 0 ? this.countLabel(this.labels.hoursShort, this.labels.hoursShort, this.weeklyHours) : '';

            if (this.scheduleMode === '1') {
                // Each range is isolated: inside Arabic text "09:00–17:00"
                // would otherwise be laid out as "17:00–09:00".
                let hoursRange = this.isolate(`${this.workHoursFrom}–${this.workHoursTo}`);

                if (this.hasSecondShift()) {
                    hoursRange += `${this.locale === 'ar' ? '، ' : ', '}${this.isolate(`${this.workHours2From}–${this.workHours2To}`)}`;
                }

                return [this.fixedDayRangeLabel, hoursRange, hours].filter(Boolean).join(' · ');
            }

            return [hours || this.labels.flexible].filter(Boolean).join(' · ');
        },

        get terminationSummary() {
            if (!this.isTerminated) {
                return this.labels.notTerminated;
            }

            return [this.formatDate(this.terminatedAt), this.terminationReasonLabel]
                .filter(Boolean).join(' · ');
        },

        get paySummary() {
            if (this.payHidden) {
                return this.labels.salaryHidden;
            }

            if (this.payMode === '2') {
                return this.payTiers.length > 0
                    ? this.countLabel(this.labels.tiersCount, this.labels.tiersCount, this.payTiers.length)
                    : this.labels.flexible;
            }

            return this.salaryEcho.split(' · ')[0];
        },

        // ---- contract card ---------------------------------------------

        get cardSubtitle() {
            return [this.cityName, this.selectedProgramLabel].filter(Boolean).join(' · ')
                || this.labels.empty;
        },

        get cardPosition() {
            return this.selectedPosition?.label ?? this.labels.empty;
        },

        get cardCenter() {
            return this.selectedCenterLabel || this.labels.empty;
        },

        get cardCapacity() {
            return this.capacity ? `${this.capacity.taken} / ${this.capacity.max}` : this.labels.empty;
        },

        get cardType() {
            return this.selectedType?.name ?? this.labels.empty;
        },

        get cardStart() {
            return this.formatDate(this.startDate) || this.labels.empty;
        },

        get cardEnd() {
            return this.formatDate(this.endDate) || this.labels.empty;
        },

        // A reason recorded without a date still terminates the contract, so
        // the row falls back to the reason rather than reading as empty.
        get cardTerminated() {
            return this.formatDate(this.terminatedAt) || this.terminationReasonLabel || this.labels.empty;
        },

        get cardPeriod() {
            return this.durationLabel || this.labels.empty;
        },

        get cardWorkDays() {
            return (this.scheduleMode === '1' ? this.fixedDayRangeLabel : '') || this.labels.empty;
        },

        get cardHours() {
            return Number(this.weeklyHours) >= 1
                ? this.countLabel(this.labels.hoursShort, this.labels.hoursShort, this.weeklyHours)
                : this.labels.empty;
        },

        get cardSalary() {
            if (this.payHidden) {
                return this.labels.salaryHidden;
            }

            if (this.payMode === '2') {
                return this.payTiers.length > 0
                    ? this.countLabel(this.labels.tiersCount, this.labels.tiersCount, this.payTiers.length)
                    : this.labels.empty;
            }

            return this.salary === '' || this.salaryError
                ? this.labels.empty
                : `${this.formatNumber(this.salary)} ${this.currencyLabel} ${this.salaryPerLabel}`;
        },

        get cardLeave() {
            return this.leaveDays === null
                ? this.labels.empty
                : this.countLabel(this.labels.daysCount, this.labels.daysDecimal, this.leaveDays, this.formatNumber(this.leaveDays));
        },

        // =============================================================
        // Leave preview
        // =============================================================

        get leaveInputs() {
            return [
                this.selected.employee,
                this.selected.contractType,
                this.startDate,
                this.endDate,
                this.weeklyHours,
            ].join('|');
        },

        scheduleLeavePreview() {
            clearTimeout(this.leaveTimer);

            if (!this.selected.employee || !this.startDate || !this.endDate
                || Number(this.weeklyHours) < 1) {
                this.leaveDays = null;

                return;
            }

            this.leaveTimer = setTimeout(async () => {
                this.leaveDays = await this.$wire.leavePreview({
                    employeeId: this.selected.employee,
                    contractTypeId: this.selected.contractType,
                    startDate: this.startDate,
                    endDate: this.endDate,
                    weeklyHours: this.weeklyHours,
                });
            }, 500);
        },

        // =============================================================
        // Submit
        // =============================================================

        async submit() {
            if (!this.canSubmit) {
                return;
            }

            this.submitting = true;

            try {
                await this.$wire.submit(this.payload());
            } finally {
                this.submitting = false;
            }
        },

        /** Everything submit() sends; also what "unsaved changes" compares. */
        payload() {
            const payload = {
                cityId: this.selected.city,
                employeeId: this.selected.employee,
                programId: this.selected.program,
                positionId: this.selected.position,
                draftContractId: this.draftContractId,
                contractTypeId: this.selected.contractType,
                type: this.type,
                place: this.place,
                startDate: this.startDate,
                endDate: this.endDate,
                scheduleMode: this.scheduleMode,
                workHoursFrom: this.workHoursFrom,
                workHoursTo: this.workHoursTo,
                weeklyHours: this.weeklyHours,
                workHours2From: this.workHours2From,
                workHours2To: this.workHours2To,
                workDayFrom: this.workDayFrom,
                workDayTo: this.workDayTo,
                showDaysPerWeek: this.showDaysPerWeek,
                showHoursPerWeek: this.showHoursPerWeek,
                showHoursPerDay: this.showHoursPerDay,
                showDayRange: this.showDayRange,
                flexDaysPerWeek: this.flexDaysPerWeek,
                flexHoursPerWeek: this.flexHoursPerWeek,
                flexHoursPerMonth: this.flexHoursPerMonth,
                flexVariesByRequirements: this.flexVariesByRequirements,
                flexCoordinatedWithManager: this.flexCoordinatedWithManager,
                flexCustomEn: this.flexCustomEn,
                flexCustomAr: this.flexCustomAr,
                payMode: this.payMode,
                salary: this.salary,
                currency: this.currency,
                salaryPer: this.salaryPer,
                sessionDuration: this.sessionDuration,
                payTiers: this.payTiers,
                // Ignored by ContractCreate, which always writes a contract
                // that is active and un-terminated.
                terminatedAt: this.terminatedAt,
                terminationReasonId: this.terminationReasonId,
                reasonOfTermination: this.reasonOfTermination,
            };

            if (this.canManageAutoRenew) {
                payload.autoRenew = this.autoRenew;
            }

            return payload;
        },
    }));
});
