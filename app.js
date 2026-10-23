'use strict';

async function readJsonResponse(response, message) {
    const body = await response.text();
    try {
        return JSON.parse(body);
    } catch (_) {
        const title = body.match(/<title[^>]*>([^<]*)<\/title>/i)?.[1]?.trim() || '';
        console.error('Expected JSON: ' + JSON.stringify({url: response.url, status: response.status,
            contentType: response.headers.get('Content-Type'), title}));
        throw new Error(message + ' The server returned an unexpected response (HTTP ' + response.status + ').');
    }
}

function startTheatreApp(initial) {
    initial.area = initial.area || 'theatre';
    const publicBooking = initial.public === true;
    const holdUrl = publicBooking ? 'public_hold.php' : 'admin_hold.php';
    initial.days = initial.days || {[initial.day]: initial.day_label};
    let publicStep = 1;
    let paymentAssignment = null;
    let paymentAssignmentRequest = null;
    let activeHoldKey = '';
    let holdReady = false;
    let holdAcquired = false;
    let holdRequest = null;
    let nextHoldRenewal = 0;
    let selectedSeats = [];
    let currentState = initial;
    let currentRevision = initial.revision;
    const selectedApprovalGroups = new Map();
    const reservationSearchIndex = new WeakMap();
    let reservationSearchQuery = '';
    let modalSeatCodes = [];
    let reservationConflictActive = false;
    let reservationPhase = 'idle';
    let reservationAttempt = 0;
    let activeSeatInfo = null;
    let activeEditingId = null;
    let editingFingerprint = '';
    let refreshPromise = null;
    let stopped = false;
    const reservationFormElement = document.getElementById('reservationForm');
    const reservationSubmit = reservationFormElement.querySelector('button[type=submit]');

    function seatDetails(value) {
        const separator = value.indexOf('|');
        return separator < 0 ? {day: initial.day, code: value}
            : {day: value.slice(0, separator), code: value.slice(separator + 1)};
    }

    function seatBasket(keys) {
        const basket = {};
        for (const key of keys) {
            const {day, code} = seatDetails(key);
            (basket[day] ||= []).push(code);
        }
        return basket;
    }

    function occupiedSeat(key) {
        const {day, code} = seatDetails(key);
        const seat = currentState.calendar[day]?.seats[code];
        return seat?.on_hold && seat.hold_mine ? null : seat;
    }

    function holdFields(key, action, codes = modalSeatCodes) {
        const fields = new URLSearchParams();
        fields.set('csrf_token', reservationFormElement.elements.csrf_token.value);
        fields.set('request_key', reservationFormElement.elements.request_key.value);
        fields.set('hold_key', key);
        fields.set('hold_action', action);
        if (action !== 'release') fields.set('seat_basket', JSON.stringify(seatBasket(codes)));
        return fields;
    }

    function releaseSeatHold() {
        if (!activeHoldKey) return;
        const key = activeHoldKey;
        activeHoldKey = '';
        holdReady = false;
        holdAcquired = false;
        document.getElementById('seatHoldKey').value = '';
        const release = () => fetch(holdUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
            body: holdFields(key, 'release'),
        }).then(() => refreshState({force: true})).catch(() => {});
        // If a close races the acquire request, release after that request finishes as well.
        if (holdRequest) holdRequest.finally(release);
        release();
    }

    async function maintainSeatHold() {
        if (!activeHoldKey || reservationConflictActive) return false;
        if (holdRequest) return holdRequest;
        const key = activeHoldKey;
        const codes = [...modalSeatCodes];
        const status = document.getElementById('seatHoldStatus');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        holdRequest = (async () => {
            try {
                const response = await fetch(holdUrl, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                    body: holdFields(key, holdAcquired ? 'renew' : 'acquire', codes),
                });
                const result = await readJsonResponse(response, 'Could not check seat availability.');
                if (key !== activeHoldKey) return false;
                if (response.status === 401) {
                    stopped = true;
                    window.location.replace('login.php');
                    return false;
                }
                if (response.status === 409) {
                    showReservationConflict(result.unavailable_seats, true, result.hold_expired);
                    return false;
                }
                if (!response.ok || !result.ok) throw new Error(result.error || 'Could not check seat availability.');
                holdReady = true;
                holdAcquired = true;
                nextHoldRenewal = Date.now() + 15000;
                status.textContent = '';
                status.hidden = true;
                return true;
            } catch (_) {
                if (key !== activeHoldKey) return false;
                holdReady = false;
                nextHoldRenewal = Date.now() + 3000;
                status.textContent = 'Reconnecting. Please wait before continuing.';
                status.hidden = false;
                return false;
            } finally {
                clearTimeout(timeout);
            }
        })();
        try {
            return await holdRequest;
        } finally {
            holdRequest = null;
            setReservationButton(reservationConflictActive || reservationPhase !== 'idle');
        }
    }

    function seatLabel(key) {
        const {day, code} = seatDetails(key);
        const area = code.startsWith('B-') ? 'Balcony ' + code.slice(2) : 'Main Theatre ' + code;
        return initial.days[day] + ' - ' + area;
    }

    function bookingDescription(keys) {
        return keys.map(seatLabel).join('; ');
    }

    function syncNavigation() {
        for (const link of document.querySelectorAll('.day-selector:not(.area-selector) a')) {
            const target = new URL(link.href);
            const day = target.searchParams.get('day');
            target.searchParams.set('area', initial.area);
            link.href = target.href;
            link.classList.toggle('active', day === initial.day);
            if (day === initial.day) link.setAttribute('aria-current', 'date');
            else link.removeAttribute('aria-current');
            link.textContent = initial.days[day];
            const count = selectedSeats.filter(key => seatDetails(key).day === day).length;
            if (count) {
                const badge = document.createElement('span');
                badge.className = 'date-selection-count';
                badge.textContent = count;
                link.appendChild(badge);
            }
            link.setAttribute('aria-label', initial.days[day] + (count ? ', ' + count + ' seats selected' : ''));
        }
        for (const link of document.querySelectorAll('.area-selector a')) {
            const target = new URL(link.href);
            target.searchParams.set('day', initial.day);
            link.href = target.href;
            const active = link.dataset.area === initial.area;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        }
        for (const link of document.querySelectorAll('.tab-navigation a')) {
            const target = new URL(link.href);
            target.searchParams.set('day', initial.day);
            target.searchParams.set('area', initial.area);
            link.href = target.href;
        }
        for (const field of document.querySelectorAll('input[name="day"]:not([data-fixed-day])')) field.value = initial.day;
        for (const field of document.querySelectorAll('input[name="area"]')) field.value = initial.area;
        for (const form of document.querySelectorAll('form[data-reservation-day]')) {
            // The hidden input named "action" masks the form.action property.
            const target = new URL(form.getAttribute('action') || window.location.href, document.baseURI);
            target.searchParams.set('day', form.dataset.reservationDay);
            target.searchParams.set('area', initial.area);
            form.setAttribute('action', target.href);
        }
    }

    function normalizeReservationSearch(value) {
        return String(value).normalize('NFKC').toLocaleLowerCase()
            .replace(/[\u0660-\u0669]/g, digit => String(digit.charCodeAt(0) - 0x0660))
            .replace(/[\u06F0-\u06F9]/g, digit => String(digit.charCodeAt(0) - 0x06F0))
            .replace(/[\u064B-\u065F\u0670]/g, '').replace(/\s+/g, ' ').trim();
    }

    function matchesReservationSearch(element, query) {
        if (!query) return true;
        let records = reservationSearchIndex.get(element);
        if (!records) {
            records = JSON.parse(element.dataset.searchRecords).map(record => ({
                name: normalizeReservationSearch(record.name),
                phone: normalizeReservationSearch(record.phone).replace(/\D/g, ''),
            }));
            reservationSearchIndex.set(element, records);
        }
        const phoneMatches = term => {
            if (!/^[+\d\s().-]+$/.test(term)) return false;
            const digits = term.replace(/\D/g, '');
            if (!digits) return false;
            const localDigits = digits.replace(/^(?:0020|20)/, '').replace(/^0/, '');
            return records.some(record => record.phone.includes(digits) || (localDigits &&
                record.phone.replace(/^(?:0020|20)/, '').replace(/^0/, '').includes(localDigits)));
        };
        if (records.some(record => record.name.includes(query)) || phoneMatches(query)) return true;
        return query.split(' ').every(term => records.some(record => record.name.includes(term)) || phoneMatches(term));
    }

    function applyReservationSearch() {
        if (publicBooking) return;
        const panel = document.getElementById('reservationDataPanel');
        const query = normalizeReservationSearch(reservationSearchQuery);
        let pendingCount = 0;
        let confirmedCount = 0;
        for (const card of panel.querySelectorAll('[data-approval-group]')) {
            card.hidden = !matchesReservationSearch(card, query);
            if (!card.hidden) pendingCount++;
        }
        for (const row of panel.querySelectorAll('[data-confirmed-reservation]')) {
            row.hidden = !(matchesReservationSearch(row, query) && (query || row.dataset.performanceDate === initial.day));
            if (!row.hidden) confirmedCount++;
        }
        for (const overview of panel.querySelectorAll('[data-daily-overview]')) overview.hidden = !!query;
        const confirmedResults = panel.querySelector('[data-confirmed-results]');
        if (confirmedResults) confirmedResults.hidden = confirmedCount === 0;
        const confirmedEmpty = panel.querySelector('[data-confirmed-empty]');
        if (confirmedEmpty) {
            confirmedEmpty.hidden = confirmedCount > 0;
            confirmedEmpty.textContent = query ? 'No confirmed reservations match this search.'
                : 'No confirmed reservations found for ' + initial.days[initial.day] + '.';
        }
        const confirmedScope = panel.querySelector('[data-confirmed-scope]');
        if (confirmedScope) confirmedScope.textContent = query ? 'All dates' : initial.days[initial.day];
        const pendingEmpty = panel.querySelector('[data-pending-empty]');
        if (pendingEmpty) pendingEmpty.hidden = pendingCount > 0;
        const pendingToolbar = panel.querySelector('[data-pending-toolbar]');
        if (pendingToolbar) pendingToolbar.hidden = pendingCount === 0;
        const selectAllLabel = panel.querySelector('.pending-select-all');
        if (selectAllLabel) selectAllLabel.lastChild.textContent = query ? ' Select all matching pending bookings' : ' Select all pending bookings';
        const status = document.getElementById('reservationSearchStatus');
        const summary = pendingCount + ' pending bookings | ' + confirmedCount + ' confirmed reservations' +
            (query ? ' across both dates.' : ' for ' + initial.days[initial.day] + '; pending bookings include both dates.');
        if (status.textContent !== summary) status.textContent = summary;
        document.getElementById('clearReservationSearch').disabled = !reservationSearchQuery;
        syncApprovalSelection();
    }

    if (!publicBooking) {
        const search = document.getElementById('reservationSearch');
        search.addEventListener('input', () => {
            reservationSearchQuery = search.value;
            applyReservationSearch();
        });
        document.getElementById('clearReservationSearch').addEventListener('click', () => {
            search.value = '';
            reservationSearchQuery = '';
            applyReservationSearch();
            search.focus();
        });
    }

    function syncApprovalSelection() {
        if (publicBooking) return;
        const allCards = [...document.querySelectorAll('[data-approval-group]')];
        const cards = allCards.filter(card => !card.hidden);
        for (const card of allCards.filter(card => card.hidden)) {
            selectedApprovalGroups.delete(card.dataset.approvalGroup);
            card.querySelector('[data-approval-checkbox]').checked = false;
            card.classList.remove('is-selected');
        }
        const available = new Set();
        for (const card of cards) {
            const key = card.dataset.approvalGroup;
            available.add(key);
            const selected = selectedApprovalGroups.get(key);
            if (selected && selected.version !== card.dataset.approvalVersion) selectedApprovalGroups.delete(key);
            const checked = selectedApprovalGroups.has(key);
            card.querySelector('[data-approval-checkbox]').checked = checked;
            card.classList.toggle('is-selected', checked);
        }
        for (const key of selectedApprovalGroups.keys()) {
            if (!available.has(key)) selectedApprovalGroups.delete(key);
        }
        const count = selectedApprovalGroups.size;
        const button = document.getElementById('approveSelectedButton');
        if (button) {
            button.disabled = count === 0;
            button.textContent = count ? 'Approve selected bookings (' + count + ')' : 'Approve selected bookings';
        }
        const selectAll = document.getElementById('selectAllPending');
        if (selectAll) {
            selectAll.checked = cards.length > 0 && count === cards.length;
            selectAll.indeterminate = count > 0 && count < cards.length;
        }
    }

    function setApprovalCardSelection(card, selected) {
        if (selected) selectedApprovalGroups.set(card.dataset.approvalGroup, {
            version: card.dataset.approvalVersion,
            ids: JSON.parse(card.dataset.approvalIds),
        });
        else selectedApprovalGroups.delete(card.dataset.approvalGroup);
    }

    if (!publicBooking) {
        document.addEventListener('change', event => {
            if (event.target.matches('[data-approval-checkbox]')) {
                setApprovalCardSelection(event.target.closest('[data-approval-group]'), event.target.checked);
                syncApprovalSelection();
            } else if (event.target.id === 'selectAllPending') {
                for (const card of document.querySelectorAll('[data-approval-group]')) {
                    if (!card.hidden) setApprovalCardSelection(card, event.target.checked);
                }
                syncApprovalSelection();
            }
        });
        document.addEventListener('submit', event => {
            if (event.target.id !== 'approveSelectedForm') return;
            syncApprovalSelection();
            if (!selectedApprovalGroups.size || !confirm('Approve ' + selectedApprovalGroups.size + ' selected bookings across all dates after verifying their payment screenshots?')) {
                event.preventDefault();
                return;
            }
            const fields = document.getElementById('selectedApprovalInputs');
            fields.replaceChildren();
            for (const selected of selectedApprovalGroups.values()) {
                for (const id of selected.ids) {
                    const field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'reservation_ids[]';
                    field.value = id;
                    fields.appendChild(field);
                }
            }
        });
    }

    function paintReservationData() {
        const panel = document.getElementById('reservationDataPanel');
        const state = currentState.calendar[initial.day];
        if (panel && state?.reservations_html) {
            const positions = [...panel.querySelectorAll('.reservations-container')].map(element => element.scrollLeft);
            panel.innerHTML = state.reservations_html;
            [...panel.querySelectorAll('.reservations-container')].forEach((element, index) => {
                element.scrollLeft = positions[index] || 0;
            });
        }
        syncNavigation();
        applyReservationSearch();
    }

    function updateViewHistory() {
        const target = new URL(window.location.href);
        target.searchParams.set('day', initial.day);
        target.searchParams.set('area', initial.area);
        window.history.pushState(null, '', target);
    }

    function switchPerformanceDay(day, updateHistory = true) {
        if (!initial.days[day]) return;
        initial.day = day;
        initial.day_label = initial.days[day];
        closeSeatInfo();
        if (activeEditingId !== null) closeEditModal();
        paintReservationData();
        syncSeats();
        if (updateHistory) updateViewHistory();
    }

    function updatePaymentContactLinks() {
        const name = document.getElementById('customer_name').value.trim();
        for (const link of document.querySelectorAll('.payment-whatsapp')) {
            if (!paymentAssignment) {
                link.removeAttribute('href');
                continue;
            }
            link.href = paymentAssignment.whatsapp_url + '?text=' + encodeURIComponent(
                'Hello, my reservation name is ' + name + '. Booking: ' + bookingDescription(modalSeatCodes) +
                '. Total: EGP ' + ticketBreakdown(modalSeatCodes, false).total.toFixed(2) + '. Here is my Instapay payment screenshot.'
            );
        }
    }

    async function loadPaymentAssignment() {
        if (paymentAssignment) return paymentAssignment;
        if (paymentAssignmentRequest) return paymentAssignmentRequest;
        const status = document.getElementById('paymentAssignmentStatus');
        const retry = document.getElementById('retryPaymentAssignment');
        status.textContent = 'Loading payment details...';
        status.hidden = false;
        retry.hidden = true;
        document.getElementById('reservationFormError').hidden = true;
        setReservationButton(true, 'Loading payment details...');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        const fields = new URLSearchParams();
        fields.set('csrf_token', reservationFormElement.elements.csrf_token.value);
        fields.set('request_key', reservationFormElement.elements.request_key.value);
        paymentAssignmentRequest = (async () => {
            const response = await fetch(initial.payment_url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json'}, body: fields, signal: controller.signal,
            });
            const result = await readJsonResponse(response, 'Could not load payment details.');
            if (!response.ok) throw new Error(result.error || 'Could not load payment details.');
            if (!result.recipient || !result.assignment || !result.qr || !result.url || !result.whatsapp_url || !result.phone) {
                throw new Error('Could not load payment details.');
            }
            paymentAssignment = result;
            document.getElementById('paymentRecipient').value = result.recipient;
            document.getElementById('paymentAssignment').value = result.assignment;
            for (const image of document.querySelectorAll('[data-payment-qr]')) image.src = result.qr;
            for (const link of document.querySelectorAll('[data-payment-qr-link]')) link.href = result.qr;
            for (const link of document.querySelectorAll('[data-payment-redirect]')) link.href = result.url;
            for (const phone of document.querySelectorAll('[data-payment-phone]')) phone.textContent = result.phone;
            for (const contact of document.querySelectorAll('[data-payment-contact]')) contact.hidden = false;
            for (const account of document.querySelectorAll('[data-payment-account]')) account.hidden = false;
            updatePaymentContactLinks();
            status.hidden = true;
            return result;
        })();
        try {
            return await paymentAssignmentRequest;
        } catch (error) {
            status.textContent = 'Payment details could not be loaded. Please retry before continuing.';
            retry.hidden = false;
            showReservationError(error.name === 'AbortError' ? 'Could not load payment details. Please try again.' : error.message);
            throw error;
        } finally {
            clearTimeout(timeout);
            paymentAssignmentRequest = null;
            setReservationButton(reservationConflictActive || reservationPhase !== 'idle');
        }
    }

    function setPublicStep(step) {
        publicStep = step;
        for (const section of document.querySelectorAll('[data-booking-step]')) {
            section.hidden = Number(section.dataset.bookingStep) !== step;
        }
        for (const indicator of document.querySelectorAll('[data-step-indicator]')) {
            const current = Number(indicator.dataset.stepIndicator) === step;
            indicator.classList.toggle('current', current);
            if (current) indicator.setAttribute('aria-current', 'step');
            else indicator.removeAttribute('aria-current');
        }
        document.getElementById('paymentAcknowledged').value = step === 3 ? '1' : '0';
        const name = document.getElementById('customer_name').value.trim();
        const phone = document.getElementById('phone_number').value.trim();
        document.getElementById('paymentCustomerSummary').textContent = name + ' - ' + phone;
        updatePaymentContactLinks();
    }

    function validatePublicDetails() {
        const name = document.getElementById('customer_name');
        name.value = name.value.trim();
        const phone = document.getElementById('phone_number');
        phone.setCustomValidity(/^\+?[0-9][0-9\s()-]{6,19}$/.test(phone.value.trim()) ? '' : 'Enter a valid phone number.');
        if (!reservationFormElement.checkValidity()) {
            setPublicStep(1);
            reservationFormElement.reportValidity();
            return false;
        }
        return true;
    }

    if (publicBooking) {
        const confirmation = document.getElementById('bookingConfirmation');
        if (confirmation) {
            document.getElementById('bookingConfirmationClose').addEventListener('click', () => confirmation.close());
            confirmation.showModal();
            const target = new URL(window.location.href);
            target.searchParams.delete('requested');
            target.searchParams.delete('request');
            window.history.replaceState(null, '', target);
        }
        document.getElementById('retryPaymentAssignment').addEventListener('click', () => loadPaymentAssignment().catch(() => {}));
        document.getElementById('phone_number').addEventListener('input', event => event.target.setCustomValidity(''));
        for (const button of document.querySelectorAll('[data-step-forward]')) {
            button.addEventListener('click', async () => {
                if (reservationPhase !== 'idle' || reservationConflictActive || !paymentAssignment || !validatePublicDetails()) return;
                const attempt = ++reservationAttempt;
                reservationPhase = 'checking';
                document.getElementById('reservationFormError').hidden = true;
                setReservationButton(true, 'Checking availability...');
                try {
                    if (!await maintainSeatHold()) return;
                    const fresh = await refreshState({force: true, seats: [...modalSeatCodes]});
                    if (attempt !== reservationAttempt || !document.getElementById('reservationModal').classList.contains('active')) return;
                    if (!fresh.unavailable_seats.length && !reservationConflictActive) setPublicStep(Number(button.dataset.stepForward));
                } catch (_) {
                    if (attempt === reservationAttempt) showReservationError('Could not check availability. Check your connection and try again.');
                } finally {
                    if (attempt === reservationAttempt) {
                        reservationPhase = 'idle';
                        setReservationButton(reservationConflictActive);
                    }
                }
            });
        }
        for (const button of document.querySelectorAll('[data-step-back]')) {
            button.addEventListener('click', () => {
                if (reservationPhase === 'idle' && !reservationConflictActive) setPublicStep(Number(button.dataset.stepBack));
            });
        }
    }

    function ticketBreakdown(codes, isKhedma) {
        const basket = seatBasket(codes);
        const days = Object.keys(basket).sort();
        const parts = [];
        let total = 0;
        for (const day of days) {
            const seats = basket[day];
            const theatreCount = seats.filter(code => !code.startsWith('B-')).length;
            const balconyCount = seats.length - theatreCount;
            const theatrePrice = initial.prices[isKhedma ? 'khedma' : 'theatre'];
            if (days.length > 1) parts.push(initial.days[day]);
            if (theatreCount) parts.push((isKhedma ? 'Khedma Theatre' : 'Main Theatre') + ': ' + theatreCount + ' x ' + theatrePrice + ' EGP');
            if (balconyCount) parts.push('Balcony: ' + balconyCount + ' x ' + initial.prices.balcony + ' EGP');
            total += theatreCount * theatrePrice + balconyCount * initial.prices.balcony;
        }
        return {total, parts};
    }

    function renderPriceInfo(infoBox, breakdown) {
        infoBox.style.display = 'block';
        infoBox.textContent = breakdown.parts.join('\n') + '\nTotal: ';
        const amount = document.createElement('strong');
        amount.className = 'reservation-total-amount';
        amount.textContent = 'EGP ' + breakdown.total.toFixed(2);
        infoBox.appendChild(amount);
    }

    function switchSeatingArea(area, updateHistory = true) {
        if (!['theatre', 'balcony'].includes(area)) return;
        initial.area = area;
        for (const map of document.querySelectorAll('[data-map-area]')) map.hidden = map.dataset.mapArea !== area;
        syncNavigation();
        if (updateHistory) updateViewHistory();
    }

    for (const link of document.querySelectorAll('.area-selector a')) {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (link.dataset.area !== initial.area) switchSeatingArea(link.dataset.area);
        });
    }
    for (const link of document.querySelectorAll('.day-selector:not(.area-selector) a')) {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const day = new URL(link.href).searchParams.get('day');
            if (day !== initial.day) switchPerformanceDay(day);
        });
    }
    window.addEventListener('popstate', () => {
        const target = new URL(window.location.href);
        switchSeatingArea(target.searchParams.get('area') || 'theatre', false);
        switchPerformanceDay(target.searchParams.get('day') || Object.keys(initial.days)[0], false);
    });

    function showSeatInfo(seatId, customerName, remaining, isPending, day = initial.day) {
        activeSeatInfo = day + '|' + seatId;
        document.getElementById('seatInfoOwnerLabel').hidden = false;
        const modal = document.getElementById('seatInfoModal');
        const content = document.getElementById('seatInfoContent');
        const remainingInfo = document.getElementById('seatInfoRemaining');
        const statusElement = document.getElementById('seatInfoStatus');
        
        document.getElementById('seatInfoSeatId').textContent = initial.days[day] + ' - ' + seatId;
        document.getElementById('seatInfoCustomerName').textContent = customerName || '';
        
        if (isPending) {
            statusElement.textContent = '⏳ Status: Pending Approval';
            statusElement.style.color = '#5D5978';
            statusElement.style.fontWeight = 'bold';
            statusElement.style.display = 'block';
            content.style.borderColor = '#5D5978';
        } else {
            statusElement.style.display = 'none';
            if (remaining && parseFloat(remaining) > 0) {
                content.style.borderColor = '#A1CAE3'; // Cyan/light blue for unpaid, matching theme
            } else {
                content.style.borderColor = '#4CAF50'; // Green for paid
            }
        }
        
        if (remaining && parseFloat(remaining) > 0) {
            content.classList.add('unpaid');
            remainingInfo.style.display = 'block';
            remainingInfo.innerHTML = '💰 Remaining Amount: <br><strong>EGP ' + parseFloat(remaining).toFixed(2) + '</strong>';
        } else {
            content.classList.remove('unpaid');
            remainingInfo.style.display = 'none';
        }
        
        if (publicBooking) {
            document.getElementById('seatInfoOwnerLabel').hidden = !customerName;
            remainingInfo.style.display = 'none';
            statusElement.textContent = isPending ? 'Pending confirmation' : 'Reserved';
            statusElement.style.display = 'block';
        }
        modal.classList.add('active');
    }

    function closeSeatInfo() {
        activeSeatInfo = null;
        document.getElementById('seatInfoModal').classList.remove('active');
    }

    function toggleSeat(element) {
        if (!element.classList.contains('available')) return;
        const seatId = initial.day + '|' + element.getAttribute('data-seat');
        
        if (element.classList.contains('selected')) {
            element.classList.remove('selected');
            selectedSeats = selectedSeats.filter(seat => seat !== seatId);
        } else {
            element.classList.add('selected');
            selectedSeats.push(seatId);
        }
        
        updateReservationButton();
    }

    function updateReservationButton() {
        const actionButton = document.getElementById('reservationAction');
        const countElement = document.getElementById('selectedCount');
        
        if (selectedSeats.length > 0) {
            actionButton.classList.add('visible');
            countElement.textContent = selectedSeats.length;
        } else {
            actionButton.classList.remove('visible');
        }
        syncNavigation();
    }

    function openReservationModal() {
        if (selectedSeats.length === 0 || document.getElementById('reservationModal').classList.contains('active')) return;
        reservationAttempt++;
        reservationPhase = 'idle';
        modalSeatCodes = [...selectedSeats];
        reservationConflictActive = false;
        document.getElementById('reservationEditor').hidden = false;
        document.getElementById('reservationConflict').hidden = true;
        document.getElementById('reservationFormError').hidden = true;
        document.getElementById('reservationModal').setAttribute('aria-labelledby', 'reservationTitle');
        setReservationButton(false);


        const modal = document.getElementById('reservationModal');
        const selectedSeatsList = document.getElementById('selectedSeatsList');
        const selectedSeatsInput = document.getElementById('selectedSeatsInput');
        selectedSeatsList.innerHTML = '';
        const basket = seatBasket(modalSeatCodes);
        for (const day of Object.keys(basket).sort()) {
            const heading = document.createElement('div');
            heading.className = 'selected-seats-day';
            heading.textContent = initial.days[day];
            selectedSeatsList.appendChild(heading);
            for (const code of basket[day]) {
                const tag = document.createElement('span');
                tag.className = 'selected-seat-tag';
                tag.textContent = code.startsWith('B-') ? 'Balcony ' + code.slice(2) : 'Main Theatre ' + code;
                selectedSeatsList.appendChild(tag);
            }
        }
        selectedSeatsInput.value = (basket[initial.day] || []).join(', ');
        document.getElementById('seatBasketInput').value = JSON.stringify(basket);
        document.getElementById('reservationLocation').textContent = Object.keys(basket).sort().map(day => initial.days[day]).join(' / ');

        const bytes = crypto.getRandomValues(new Uint8Array(16));
        activeHoldKey = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        document.getElementById('seatHoldKey').value = activeHoldKey;
        holdReady = false;
        holdAcquired = false;
        nextHoldRenewal = 0;
        const status = document.getElementById('seatHoldStatus');
        status.textContent = 'Checking seat availability...';
        status.hidden = false;
        setReservationButton(true);
        // Reset khedma state each time modal opens.
        if (publicBooking) {
            setPublicStep(1);
            updateNewPriceInfo();
            loadPaymentAssignment().catch(() => {});
        } else {
            document.getElementById('khedmaCheckbox').checked = false;
            toggleRemainingInput();
        }

        modal.classList.add('active');
        maintainSeatHold().then(() => refreshState({force: true, seats: modalSeatCodes})).catch(() => {});
    }

    function closeReservationModal() {
        if (reservationPhase === 'saving') return;
        releaseSeatHold();
        reservationAttempt++;
        reservationPhase = 'idle';
        if (reservationConflictActive) {
            selectedSeats = [];
            syncSeats();
        }
        modalSeatCodes = [];
        reservationConflictActive = false;

        const modal = document.getElementById('reservationModal');
        modal.classList.remove('active');
    }

    function toggleRemainingInput() {
        const unpaid = document.getElementById('unpaidCheckbox').checked;
        const remainingInput = document.getElementById('remaining');
        document.getElementById('remainingGroup').classList.toggle('visible', unpaid);
        remainingInput.required = unpaid;
        document.getElementById('isPaidInput').value = unpaid ? '0' : '1';
        if (!unpaid) remainingInput.value = '0';
        updateNewPriceInfo();
    }

    function updateKhedmaLabel(checkboxId, codes) {
        const balconyNote = codes.some(code => seatDetails(code).code.startsWith('B-'))
            ? '; balcony stays ' + initial.prices.balcony + ' EGP' : '';
        document.querySelector('label[for="' + checkboxId + '"]').textContent =
            'Khedma (' + initial.prices.khedma + ' EGP / theatre ticket' + balconyNote + ')';
    }

    function updateNewPriceInfo() {
        if (!publicBooking) updateKhedmaLabel('khedmaCheckbox', modalSeatCodes);
        const isKhedma = !publicBooking && document.getElementById('khedmaCheckbox').checked;
        const breakdown = ticketBreakdown(modalSeatCodes, isKhedma);
        const infoBox = document.getElementById('newPriceInfo');
        renderPriceInfo(infoBox, breakdown);
        if (!publicBooking && document.getElementById('unpaidCheckbox').checked) {
            document.getElementById('remaining').value = breakdown.total.toFixed(2);
        }
    }

    function updateEditPriceInfo(recalculateRemaining = true) {
        const isKhedma = document.getElementById('edit_khedmaCheckbox').checked;
        const codes = document.getElementById('edit_reserved_desks').value.split(',').map(code => code.trim()).filter(Boolean);
        updateKhedmaLabel('edit_khedmaCheckbox', codes);
        const breakdown = ticketBreakdown(codes, isKhedma);
        const infoBox = document.getElementById('editPriceInfo');
        renderPriceInfo(infoBox, breakdown);
        if (recalculateRemaining && codes.length > 0 && Number(document.getElementById('edit_remaining').value) > 0) {
            document.getElementById('edit_remaining').value = breakdown.total.toFixed(2);
        }
    }


    function openEditModal(reservation) {
        if (reservation.performance_date !== initial.day) switchPerformanceDay(reservation.performance_date);
        activeEditingId = String(reservation.id);
        editingFingerprint = JSON.stringify(reservation);
        document.getElementById('editLiveNotice').hidden = true;
        document.querySelector('#editForm button[type=submit]').disabled = false;

        document.getElementById('editId').value = reservation.id;
        document.getElementById('edit_customer_name').value = reservation.customer_name;
        document.getElementById('edit_phone_number').value = reservation.customer_phone;
        document.getElementById('edit_reserved_desks').value = reservation.seat_codes;
        document.getElementById('edit_remaining').value = reservation.remaining_amount;
        document.getElementById('edit_received_ticket').checked = reservation.tickets_received == 1;

        // Pre-populate khedma state from saved reservation
        const khedmaBox = document.getElementById('edit_khedmaCheckbox');
        khedmaBox.checked = reservation.has_service_discount == 1;
        updateEditPriceInfo(false); // Opening the form preserves the saved balance.

        document.getElementById('editModal').classList.add('active');
        syncEditingReservation();
    }

    function closeEditModal() {
        activeEditingId = null;
        document.getElementById('editModal').classList.remove('active');
    }

    function confirmDelete(id, day = initial.day) {
        if (confirm('Are you sure you want to delete this reservation? This action cannot be undone.')) {
            if (day !== initial.day) switchPerformanceDay(day);
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    window.onclick = function(event) {
        const reservationModal = document.getElementById('reservationModal');
        const seatInfoModal = document.getElementById('seatInfoModal');
        
        if (event.target === reservationModal) {
            closeReservationModal();
        }
        if (event.target === seatInfoModal) {
            closeSeatInfo();
        }
        

        const editModal = document.getElementById('editModal');
        if (event.target === editModal) {
            closeEditModal();
        }

    }


    function showLiveNotice(message) {
        const notice = document.getElementById('liveNotice');
        notice.textContent = message;
        notice.hidden = !message;
    }

    function setReservationButton(disabled, text = publicBooking ? 'Confirm reservation' : 'Confirm Reservation') {
        reservationSubmit.disabled = disabled || !holdReady || (publicBooking && !paymentAssignment);
        reservationSubmit.textContent = text;
        if (publicBooking) {
            for (const button of document.querySelectorAll('[data-step-forward], [data-step-back]')) {
                button.disabled = disabled || (button.hasAttribute('data-step-forward') && (!paymentAssignment || !holdReady));
            }
        }
    }

    function showReservationError(message) {
        const error = document.getElementById('reservationFormError');
        error.textContent = message;
        error.hidden = false;
    }

    function showReservationConflict(codes, fromSave = false, holdExpired = false) {
        const modal = document.getElementById('reservationModal');
        if (!modal.classList.contains('active') || (reservationPhase === 'saving' && !fromSave)) return;
        codes = [...new Set(codes)].filter(code => modalSeatCodes.includes(code));
        if (codes.length === 0) return;
        reservationConflictActive = true;
        releaseSeatHold();
        document.getElementById('reservationEditor').hidden = true;
        document.getElementById('reservationConflict').hidden = false;
        modal.setAttribute('aria-labelledby', 'reservationConflictTitle');
        const labels = codes.map(seatLabel);
        const subject = codes.length === 1 ? 'Seat ' + labels[0] + ' is' : 'Seats ' + labels.join(', ') + ' are';
        const held = codes.some(key => occupiedSeat(key)?.on_hold);
        document.getElementById('reservationConflictMessage').textContent = holdExpired
            ? 'Your reservation session expired. Your selection has been cleared. Close this window and choose available seats from the updated map.'
            : subject + ' no longer available. Another person ' + (held ? 'is completing a reservation for these seats' : 'reserved or requested these seats') +
                ' while this window was open. Your selection has been cleared. Close this window and choose available seats from the updated map.';
        selectedSeats = [];
        syncSeats();
        setReservationButton(true);
        document.getElementById('reservationConflictClose').focus();
    }

    function syncSeats() {
        const selected = new Set(selectedSeats);
        for (const element of document.querySelectorAll('.seat[data-seat]')) {
            if (element.hasAttribute('data-vip-days')) {
                const vip = element.dataset.vipDays.split(',').includes(initial.day);
                element.classList.toggle('sound-control', vip);
                if (vip) {
                    element.classList.remove('available', 'reserved', 'reserved-unpaid', 'pending', 'selected', 'on-hold', 'own-seat');
                    element.title = 'VIP';
                    element.onclick = null;
                }
            }
            if (element.classList.contains('blocked') || element.classList.contains('sound-control')) continue;
            const code = element.dataset.seat;
            const key = initial.day + '|' + code;
            const booking = currentState.calendar[initial.day]?.seats[code];
            element.classList.toggle('own-seat', publicBooking && !!booking?.mine);
            element.classList.remove('available', 'reserved', 'reserved-unpaid', 'pending', 'selected', 'on-hold');
            if (booking?.on_hold) {
                element.classList.add('on-hold');
                element.title = booking.hold_mine ? 'On hold for you while this window is open' : 'On hold - Someone is completing a reservation';
                element.onclick = null;
            } else if (booking) {
                element.classList.add(booking.pending ? 'pending' : booking.remaining_amount > 0 ? 'reserved-unpaid' : 'reserved');
                element.title = (booking.pending ? 'Pending confirmation' : 'Reserved') +
                    (booking.customer_name ? ' - ' + booking.customer_name : '');
                element.onclick = () => showSeatInfo(code, booking.customer_name, booking.remaining_amount, booking.pending);
            } else {
                element.classList.add('available');
                if (selected.has(key)) element.classList.add('selected');
                element.title = 'Available - Click to reserve';
                element.onclick = () => toggleSeat(element);
            }
        }
        updateReservationButton();
    }

    function syncSeatInfo() {
        if (activeSeatInfo === null) return;
        const {day, code} = seatDetails(activeSeatInfo);
        const booking = occupiedSeat(activeSeatInfo);
        if (booking && !booking.on_hold) {
            showSeatInfo(code, booking.customer_name, booking.remaining_amount, booking.pending, day);
        } else {
            const status = document.getElementById('seatInfoStatus');
            status.textContent = booking?.on_hold ? 'On hold - Someone is completing a reservation.' : 'This seat is now available.';
            status.style.display = 'block';
            status.style.color = '#207FBD';
            document.getElementById('seatInfoOwnerLabel').hidden = true;
            document.getElementById('seatInfoCustomerName').textContent = '';
            document.getElementById('seatInfoRemaining').style.display = 'none';
            document.getElementById('seatInfoContent').classList.remove('unpaid');
            document.getElementById('seatInfoContent').style.borderColor = '#207FBD';
        }
    }

    function syncEditingReservation() {
        if (activeEditingId === null) return;
        const current = currentState.calendar[initial.day].reservations.find(row => String(row.id) === activeEditingId);
        const changed = !current || JSON.stringify(current) !== editingFingerprint;
        const notice = document.getElementById('editLiveNotice');
        notice.hidden = !changed;
        if (changed) {
            notice.textContent = current
                ? 'This reservation changed while you were editing it. Close and reopen it to use the latest details.'
                : 'This reservation was removed while you were editing it. Please close this window.';
        }
        document.querySelector('#editForm button[type=submit]').disabled = changed;
    }

    function applySnapshot(snapshot) {
        if (!snapshot.changed) {
            currentRevision = snapshot.revision;
            return;
        }
        currentState = snapshot;
        const blocked = modalSeatCodes.filter(occupiedSeat);
        if (blocked.length) showReservationConflict(blocked);
        const removed = selectedSeats.filter(occupiedSeat);
        selectedSeats = selectedSeats.filter(code => !occupiedSeat(code));
        if (removed.length && !document.getElementById('reservationModal').classList.contains('active')) {
            showLiveNotice('Availability changed for ' + removed.map(seatLabel).join(', ') + '. Please choose available seats from the updated map.');
        }
        syncSeats();
        paintReservationData();
        syncSeatInfo();
        syncEditingReservation();
        currentRevision = snapshot.revision;
    }

    async function refreshState(options = {}) {
        if (stopped) throw new Error('Please sign in again.');
        if (refreshPromise) {
            if (!options.force && !options.seats) return refreshPromise;
            try { await refreshPromise; } catch (_) { /* Retry with a fresh availability request. */ }
            return refreshState(options);
        }
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        const parameters = new URLSearchParams({day: initial.day, area: initial.area, revision: options.force || options.seats ? '' : currentRevision});
        const requestedHoldKey = activeHoldKey;
        if (requestedHoldKey) parameters.set('hold_key', requestedHoldKey);
        refreshPromise = (async () => {
            const response = await fetch((initial.live_url || 'live.php') + '?' + parameters, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'Accept': 'application/json'},
            });
            if (response.status === 401) {
                stopped = true;
                window.location.replace('login.php');
                throw new Error('Please sign in again.');
            }
            if (!response.ok) throw new Error('Could not refresh reservations.');
            const snapshot = await readJsonResponse(response, 'Could not refresh availability.');
            if (requestedHoldKey !== activeHoldKey) {
                return {changed: false, unavailable_seats: []};
            }
            if (snapshot.changed && !snapshot.calendar) throw new Error('Could not refresh both dates.');
            applySnapshot(snapshot);
            snapshot.unavailable_seats = (options.seats || []).filter(occupiedSeat);
            if (snapshot.unavailable_seats?.length) showReservationConflict(snapshot.unavailable_seats);
            return snapshot;
        })();
        try {
            return await refreshPromise;
        } finally {
            clearTimeout(timeout);
            refreshPromise = null;
        }
    }

    reservationFormElement.addEventListener('submit', async event => {
        event.preventDefault();
        if (reservationPhase !== 'idle' || reservationConflictActive || modalSeatCodes.length === 0) return;
        if (publicBooking && publicStep !== 3) return;
        if (publicBooking && !paymentAssignment) {
            showReservationError('Load your payment account before confirming the booking.');
            return;
        }
        if (publicBooking && !validatePublicDetails()) return;
        if (!reservationFormElement.reportValidity()) return;
        const amount = document.getElementById('remaining');
        if (!publicBooking && document.getElementById('unpaidCheckbox').checked && !(Number(amount.value) > 0)) {
            showReservationError('Please enter a remaining amount greater than zero.');
            return;
        }
        const attempt = ++reservationAttempt;
        reservationPhase = 'checking';
        document.getElementById('reservationFormError').hidden = true;
        setReservationButton(true, 'Checking availability...');
        let completed = false;
        try {
            if (!await maintainSeatHold()) return;
            const fresh = await refreshState({force: true, seats: [...modalSeatCodes]});
            if (attempt !== reservationAttempt || !document.getElementById('reservationModal').classList.contains('active')) return;
            if (fresh.unavailable_seats.length || reservationConflictActive) {
                showReservationConflict(fresh.unavailable_seats.length ? fresh.unavailable_seats : modalSeatCodes.filter(occupiedSeat));
                return;
            }
            reservationPhase = 'saving';
            setReservationButton(true, 'Completing reservation...');
            const destination = new URL(initial.submit_url || 'admoonzz', window.location.href);
            const parameters = new URLSearchParams({day: initial.day, area: initial.area});
            if (!publicBooking) parameters.set('tab', 'map');
            destination.search = parameters.toString();
            const response = await fetch(destination, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json'}, body: new URLSearchParams(new FormData(reservationFormElement)),
            });
            if (response.status === 401) {
                stopped = true;
                window.location.replace('login.php');
                return;
            }
            const result = await readJsonResponse(response, 'Could not complete your reservation.');
            if (response.status === 409) {
                showReservationConflict(result.unavailable_seats, true, result.hold_expired);
                refreshState({force: true}).catch(() => {});
                return;
            }
            if (!response.ok) {
                showReservationError(result.error || 'Could not complete the reservation. Please try again.');
                return;
            }
            if (!result.ok) throw new Error('Could not confirm the result.');
            completed = true;
            window.location.assign(result.redirect);
        } catch (error) {
            if (attempt !== reservationAttempt) return;
            showReservationError(reservationPhase === 'checking'
                ? 'Could not check seat availability. Check your connection and try again.'
                : publicBooking ? 'Could not confirm the result. Check your seats or contact us on WhatsApp before trying again.'
                    : 'Could not confirm the result. Check Reservations Data before trying again.');
        } finally {
            if (!completed && attempt === reservationAttempt) {
                reservationPhase = 'idle';
                setReservationButton(reservationConflictActive);
            }
        }
    });

    document.getElementById('editForm')?.addEventListener('submit', event => {
        syncEditingReservation();
        if (!document.getElementById('editLiveNotice').hidden) event.preventDefault();
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (document.getElementById('reservationModal').classList.contains('active')) closeReservationModal();
        else if (document.getElementById('editModal')?.classList.contains('active')) closeEditModal();
        else closeSeatInfo();
    });
    Object.assign(window, {showSeatInfo, closeSeatInfo, toggleSeat, openReservationModal,
        closeReservationModal, toggleRemainingInput, updateNewPriceInfo, updateEditPriceInfo,
        openEditModal, closeEditModal, confirmDelete});
    syncSeats();
    applyReservationSearch();
    async function poll() {
        if (activeHoldKey && reservationPhase === 'idle' && Date.now() >= nextHoldRenewal) {
            await maintainSeatHold();
        }
        try { await refreshState(); } catch (_) { /* The status reports reconnection attempts. */ }
        if (!stopped) setTimeout(poll, 1000);
    }
    for (const event of ['focus', 'online']) window.addEventListener(event, () => refreshState().catch(() => {}));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshState().catch(() => {});
    });
    window.addEventListener('pagehide', () => {
        if (activeHoldKey && reservationPhase !== 'saving') {
            navigator.sendBeacon(holdUrl, holdFields(activeHoldKey, 'release'));
            holdReady = false;
            holdAcquired = false;
        }
    });
    poll();
}

function startLoginUpdates() {
    let checking = false;
    async function checkLogin() {
        if (checking) return;
        checking = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('live.php?auth=1', {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if (response.ok && (await response.json()).authenticated) window.location.replace('admoonzz');
        } catch (_) {
            // Leave the login form usable while the connection recovers.
        } finally {
            checking = false;
            clearTimeout(timeout);
        }
    }
    setInterval(checkLogin, 1000);
    window.addEventListener('focus', checkLogin);
    window.addEventListener('online', checkLogin);
}

const theatreState = document.getElementById('theatre-state');
if (theatreState) {
    startTheatreApp(JSON.parse(theatreState.textContent));
} else {
    startLoginUpdates();
}
