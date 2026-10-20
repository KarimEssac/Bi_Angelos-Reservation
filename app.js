'use strict';

function startTheatreApp(initial) {
    initial.area = initial.area || 'theatre';
    const publicBooking = initial.public === true;
    let publicStep = 1;
    let selectedSeats = [];
    let currentState = initial;
    let currentRevision = initial.revision;
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
        for (const link of document.querySelectorAll('.payment-whatsapp')) {
            link.href = initial.whatsapp_url + '?text=' + encodeURIComponent(
                'Hello, my reservation name is ' + name + '. Date: ' + initial.day_label +
                '. Seats: ' + modalSeatCodes.join(', ') + '. Here is my Instapay payment screenshot.'
            );
        }
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
        document.getElementById('phone_number').addEventListener('input', event => event.target.setCustomValidity(''));
        for (const button of document.querySelectorAll('[data-step-forward]')) {
            button.addEventListener('click', async () => {
                if (reservationPhase !== 'idle' || reservationConflictActive || !validatePublicDetails()) return;
                const attempt = ++reservationAttempt;
                reservationPhase = 'checking';
                document.getElementById('reservationFormError').hidden = true;
                setReservationButton(true, 'Checking availability...');
                try {
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
        const theatreCount = codes.filter(code => !code.startsWith('B-')).length;
        const balconyCount = codes.length - theatreCount;
        const theatrePrice = initial.prices[isKhedma ? 'khedma' : 'theatre'];
        const parts = [];
        if (theatreCount) parts.push((isKhedma ? 'Khedma Theatre' : 'Main Theatre') + ': ' + theatreCount + ' x ' + theatrePrice + ' EGP');
        if (balconyCount) parts.push('Balcony: ' + balconyCount + ' x ' + initial.prices.balcony + ' EGP');
        const total = theatreCount * theatrePrice + balconyCount * initial.prices.balcony;
        return {total, text: [...parts, 'Total: EGP ' + total.toFixed(2)].join('\n')};
    }

    function switchSeatingArea(area, updateHistory = true) {
        if (!['theatre', 'balcony'].includes(area)) return;
        initial.area = area;
        for (const map of document.querySelectorAll('[data-map-area]')) {
            map.hidden = map.dataset.mapArea !== area;
        }
        for (const link of document.querySelectorAll('.area-selector a')) {
            const active = link.dataset.area === area;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        }
        for (const field of document.querySelectorAll('input[name="area"]')) field.value = area;
        for (const link of document.querySelectorAll('.day-selector:not(.area-selector) a, .tab-navigation a')) {
            const target = new URL(link.href);
            target.searchParams.set('area', area);
            link.href = target.href;
        }
        if (updateHistory) {
            const target = new URL(window.location.href);
            target.searchParams.set('area', area);
            window.history.pushState(null, '', target);
        }
    }

    for (const link of document.querySelectorAll('.area-selector a')) {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (link.dataset.area !== initial.area) switchSeatingArea(link.dataset.area);
        });
    }
    window.addEventListener('popstate', () => {
        switchSeatingArea(new URL(window.location.href).searchParams.get('area') || 'theatre', false);
    });



    function showSeatInfo(seatId, customerName, remaining, isPending) {
        activeSeatInfo = seatId;
        document.getElementById('seatInfoOwnerLabel').hidden = false;
        const modal = document.getElementById('seatInfoModal');
        const content = document.getElementById('seatInfoContent');
        const remainingInfo = document.getElementById('seatInfoRemaining');
        const statusElement = document.getElementById('seatInfoStatus');
        
        document.getElementById('seatInfoSeatId').textContent = seatId;
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
        const seatId = element.getAttribute('data-seat');
        
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
    }

    function openReservationModal() {
        if (selectedSeats.length === 0) return;
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
        selectedSeats.forEach(seat => {
            const tag = document.createElement('span');
            tag.className = 'selected-seat-tag';
            tag.textContent = seat.startsWith('B-') ? 'Balcony ' + seat.slice(2) : 'Main Theatre ' + seat;
            selectedSeatsList.appendChild(tag);
        });
        selectedSeatsInput.value = selectedSeats.join(', ');
        const areas = [];
        if (selectedSeats.some(code => !code.startsWith('B-'))) areas.push('Main Theatre');
        if (selectedSeats.some(code => code.startsWith('B-'))) areas.push('Balcony');
        document.getElementById('reservationLocation').textContent = areas.join(' + ') + ' - ' + initial.day_label;

        // Reset khedma state each time modal opens
        if (publicBooking) {
            setPublicStep(1);
            updateNewPriceInfo();
        } else {
            document.getElementById('khedmaCheckbox').checked = false;
            toggleRemainingInput();
        }

        modal.classList.add('active');
        refreshState({force: true, seats: modalSeatCodes}).catch(() => {});
    }

    function closeReservationModal() {
        if (reservationPhase === 'saving') return;
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
        const balconyNote = codes.some(code => code.startsWith('B-'))
            ? '; balcony stays ' + initial.prices.balcony + ' EGP' : '';
        document.querySelector('label[for="' + checkboxId + '"]').textContent =
            'Khedma (' + initial.prices.khedma + ' EGP / theatre ticket' + balconyNote + ')';
    }

    function updateNewPriceInfo() {
        if (!publicBooking) updateKhedmaLabel('khedmaCheckbox', modalSeatCodes);
        const isKhedma = !publicBooking && document.getElementById('khedmaCheckbox').checked;
        const breakdown = ticketBreakdown(modalSeatCodes, isKhedma);
        const infoBox = document.getElementById('newPriceInfo');
        infoBox.style.display = 'block';
        infoBox.textContent = breakdown.text;
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
        infoBox.style.display = 'block';
        infoBox.textContent = breakdown.text;
        if (recalculateRemaining && codes.length > 0 && Number(document.getElementById('edit_remaining').value) > 0) {
            document.getElementById('edit_remaining').value = breakdown.total.toFixed(2);
        }
    }


    function openEditModal(reservation) {
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

    function confirmDelete(id) {
        if (confirm('Are you sure you want to delete this reservation? This action cannot be undone.')) {
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
        reservationSubmit.disabled = disabled;
        reservationSubmit.textContent = text;
        if (publicBooking) {
            for (const button of document.querySelectorAll('[data-step-forward], [data-step-back]')) button.disabled = disabled;
        }
    }

    function showReservationError(message) {
        const error = document.getElementById('reservationFormError');
        error.textContent = message;
        error.hidden = false;
    }

    function showReservationConflict(codes, fromSave = false) {
        const modal = document.getElementById('reservationModal');
        if (!modal.classList.contains('active') || (reservationPhase === 'saving' && !fromSave)) return;
        codes = [...new Set(codes)].filter(code => modalSeatCodes.includes(code));
        if (codes.length === 0) return;
        reservationConflictActive = true;
        document.getElementById('reservationEditor').hidden = true;
        document.getElementById('reservationConflict').hidden = false;
        modal.setAttribute('aria-labelledby', 'reservationConflictTitle');
        const subject = codes.length === 1 ? 'Seat ' + codes[0] + ' is' : 'Seats ' + codes.join(', ') + ' are';
        document.getElementById('reservationConflictMessage').textContent = subject +
            ' no longer available. Another person reserved or requested these seats while this window was open. Your selection has been cleared. Close this window and choose available seats from the updated map.';
        selectedSeats = [];
        syncSeats();
        setReservationButton(true);
        document.getElementById('reservationConflictClose').focus();
    }

    function syncSeats() {
        const occupied = currentState.seats;
        const selected = new Set(selectedSeats);
        for (const element of document.querySelectorAll('.seat[data-seat]')) {
            if (element.classList.contains('blocked') || element.classList.contains('sound-control')) continue;
            const code = element.dataset.seat;
            const booking = occupied[code];
            element.classList.toggle('own-seat', publicBooking && !!booking?.mine);
            element.classList.remove('available', 'reserved', 'reserved-unpaid', 'pending', 'selected');
            if (booking) {
                element.classList.add(booking.pending ? 'pending' : booking.remaining_amount > 0 ? 'reserved-unpaid' : 'reserved');
                element.title = (booking.pending ? 'Pending confirmation' : 'Reserved') +
                    (booking.customer_name ? ' - ' + booking.customer_name : '');
                element.onclick = () => showSeatInfo(code, booking.customer_name, booking.remaining_amount, booking.pending);
            } else {
                element.classList.add('available');
                if (selected.has(code)) element.classList.add('selected');
                element.title = 'Available - Click to reserve';
                element.onclick = () => toggleSeat(element);
            }
        }
        updateReservationButton();
    }

    function syncSeatInfo() {
        if (activeSeatInfo === null) return;
        const booking = currentState.seats[activeSeatInfo];
        if (booking) {
            showSeatInfo(activeSeatInfo, booking.customer_name, booking.remaining_amount, booking.pending);
        } else {
            const status = document.getElementById('seatInfoStatus');
            status.textContent = 'This seat is now available.';
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
        const current = currentState.reservations.find(row => String(row.id) === activeEditingId);
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
        currentRevision = snapshot.revision;
        if (!snapshot.changed) return;
        currentState = snapshot;
        const blocked = modalSeatCodes.filter(code => snapshot.seats[code]);
        if (blocked.length) showReservationConflict(blocked);
        const removed = selectedSeats.filter(code => snapshot.seats[code]);
        selectedSeats = selectedSeats.filter(code => !snapshot.seats[code]);
        if (removed.length && !document.getElementById('reservationModal').classList.contains('active')) {
            showLiveNotice('Availability changed for ' + removed.join(', ') + '. Please choose available seats from the updated map.');
        }
        syncSeats();
        const panel = document.getElementById('reservationDataPanel');
        if (panel) {
            const positions = [...panel.querySelectorAll('.reservations-container')].map(element => element.scrollLeft);
            panel.innerHTML = snapshot.reservations_html;
            [...panel.querySelectorAll('.reservations-container')].forEach((element, index) => {
                element.scrollLeft = positions[index] || 0;
            });
        }
        syncSeatInfo();
        syncEditingReservation();
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
        const parameters = new URLSearchParams({day: initial.day, area: initial.area, revision: options.force ? '' : currentRevision});
        if (options.seats) parameters.set('seats', options.seats.join(','));
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
            const snapshot = await response.json();
            if (snapshot.day !== initial.day || snapshot.area !== initial.area) throw new Error('Unexpected performance or seating area.');
            applySnapshot(snapshot);
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
            const fresh = await refreshState({force: true, seats: [...modalSeatCodes]});
            if (attempt !== reservationAttempt || !document.getElementById('reservationModal').classList.contains('active')) return;
            if (fresh.unavailable_seats.length || reservationConflictActive) {
                showReservationConflict(fresh.unavailable_seats.length ? fresh.unavailable_seats : modalSeatCodes.filter(code => currentState.seats[code]));
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
                headers: {'Accept': 'application/json'}, body: new FormData(reservationFormElement),
            });
            if (response.status === 401) {
                stopped = true;
                window.location.replace('login.php');
                return;
            }
            const result = await response.json();
            if (response.status === 409) {
                showReservationConflict(result.unavailable_seats, true);
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
    async function poll() {
        try { await refreshState(); } catch (_) { /* The status reports reconnection attempts. */ }
        if (!stopped) setTimeout(poll, 1000);
    }
    for (const event of ['focus', 'online']) window.addEventListener(event, () => refreshState().catch(() => {}));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshState().catch(() => {});
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
