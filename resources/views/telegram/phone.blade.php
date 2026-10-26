@extends('layouts.app')

@section('content')
<div class="container d-flex justify-content-center align-items-center" style="min-height:100vh; background:#f0f2f5;">
    <div class="auth-box p-4 rounded shadow bg-white" style="width:420px; max-width:92%; min-width:320px;">
        <h3 class="text-center mb-3 fw-bold">Telegram Login</h3>

        <div id="alerts" class="mb-3">
            <div id="alertSuccess" class="alert alert-success d-none" role="alert" aria-live="polite"></div>
            <div id="alertNeutral" class="alert alert-secondary d-none" role="status" aria-live="polite"></div>
            <div id="alertError" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
        </div>

        <!-- Phone -->
        <div id="stepPhone" class="mb-2">
            <label class="form-label small text-muted">Phone Number</label>
            <div class="d-flex gap-2">
                <input id="phone" type="text" class="form-control" value="{{ $phone ?? '' }}" placeholder="+998901234567">
                <button id="btnPhone" class="btn btn-primary" onclick="sendPhone()">Send SMS</button>
            </div>
            <div class="mt-2">
                <button id="btnRetry" class="btn btn-link p-0 d-none" onclick="window.location.reload()">Qayta urinib ko‘rish</button>
            </div>
        </div>

        <!-- Code -->
        <div id="stepCode" class="d-none mb-2">
            <label class="form-label small text-muted">SMS Code</label>
            <div class="d-flex gap-2">
                <input id="code" type="text" class="form-control" placeholder="Enter SMS code">
                <button id="btnCode" class="btn btn-primary" onclick="sendCode()">Verify</button>
            </div>
        </div>

        <!-- Password (2FA) -->
        <div id="stepPassword" class="d-none mb-2">
            <label class="form-label small text-muted">2FA Password</label>
            <div class="d-flex gap-2">
                <input id="password" type="password" class="form-control" placeholder="Enter password">
                <button id="btnPassword" class="btn btn-outline-primary" onclick="sendPassword()">Send</button>
            </div>
        </div>

        <!-- Success actions -->
        <div id="stepSuccess" class="d-none text-center mt-3">
            <div class="mb-2">✅ <strong id="successText">Connected</strong></div>
            <a id="goToMessages" href="{{ route('telegram.send-messages-form') }}" class="btn btn-success btn-sm">Habar Jonatish</a>
        </div>
    </div>
</div>

<style>
.auth-box { box-shadow: 0 8px 30px rgba(0,0,0,0.08); border-radius:12px; background:#fff; }
.spinner { width:18px; height:18px; border:3px solid #fff; border-top-color:transparent; border-radius:50%; animation:spin .8s linear infinite; display:inline-block; vertical-align:middle; }
@keyframes spin { to { transform: rotate(360deg); } }
.small-muted { font-size:0.85rem; color:#6c757d; }
</style>

<script>
/* CONFIG */
const URL_AUTH = "{{ route('telegram.phone') }}";
const URL_CODE = "{{ route('telegram.code') }}";
const URL_PASSWORD = "{{ route('telegram.password') }}";
const URL_STATUS = "{{ route('telegram.status') }}";
const CSRF = "{{ csrf_token() }}";

/* TRANSLATIONS FOR KEYS (frontend fallback) */
const TRANSLATIONS = {
    'wait': 'Biroz kuting...',
    'sms_sent': 'SMS yuborildi. Kodni kiriting.',
    'processing': 'Jarayon davom etmoqda...',
    '2fa_password_required': 'Ikki bosqichli parol talab qilinadi. Iltimos parolni kiriting.',
    'telegram_verified': 'Telegram muvaffaqiyatli bog‘landi',
    'session_not_found': 'Sessiya topilmadi — avval SMS yuboring',
    'invalid_code': 'SMS kodi noto‘g‘ri',
    'unknown_error': 'Nomaʼlum xatolik yuz berdi'
};

/* UI helpers */
function show(el){ if(el) el.classList.remove('d-none'); }
function hide(el){ if(el) el.classList.add('d-none'); }
function setText(id, txt){ const el=document.getElementById(id); if(el) el.innerText = txt; }
function showAlert(id, text, timeout=5000){
    const el=document.getElementById(id);
    if(!el) return;
    el.innerText = text;
    el.classList.remove('d-none');
    if(timeout) setTimeout(()=>el.classList.add('d-none'), timeout);
}
function showError(text){ showAlert('alertError', text, 10000); }
function showSuccess(text){ showAlert('alertSuccess', text, 4000); }
function showNeutral(text){ const el=document.getElementById('alertNeutral'); el.innerText=text; el.classList.remove('d-none'); }
function hideNeutral(){ document.getElementById('alertNeutral').classList.add('d-none'); }

/* Loading button */
function setLoading(btn, state){
    if(!btn) return;
    if(state){
        btn.dataset.orig = btn.innerHTML;
        btn.innerHTML = '<span class="spinner"></span>';
        btn.disabled = true;
    } else {
        btn.innerHTML = btn.dataset.orig || btn.innerText;
        btn.disabled = false;
    }
}

/* STATE */
let currentPhone = null;
let pollInterval = null;
let pollingEnabled = false; // when true we poll every 1.5s
let waitReloadShown = false;

/* helper for message keys */
function translateOrRaw(key, raw){
    if(key){
        return TRANSLATIONS[key] ?? key.replace(/_/g,' ');
    }
    return raw || '';
}

/* DOM step toggles */
const stepPhone = document.getElementById('stepPhone');
const stepCode = document.getElementById('stepCode');
const stepPassword = document.getElementById('stepPassword');
const stepSuccess = document.getElementById('stepSuccess');
const btnRetry = document.getElementById('btnRetry');

function enterWaitingStateAfterPhone() {
    // disable inputs & show "Kuting" and reload option
    document.getElementById('phone').disabled = true;
    setLoading(document.getElementById('btnPhone'), true);
    showNeutral('Kuting...');

    // show reload button after small delay so user can reload if stuck
    if(!waitReloadShown){
        btnRetry.classList.remove('d-none');
        waitReloadShown = true;
    }
}

/* POLLING (every 1.5s) */
function startPolling() {
    if(pollInterval) return;
    pollingEnabled = true;
    checkStatus(); // immediate
    pollInterval = setInterval(checkStatus, 1500);
}
function stopPolling(){
    if(pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
    pollingEnabled = false;
}

/* Status check */
async function checkStatus(){
    if(!currentPhone) return;
    try {
        const res = await fetch(URL_STATUS, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({ phone: currentPhone })
        });
        if(!res.ok){
            // keep showing neutral but don't spam errors
            showNeutral('Server javobi kutilmoqda...');
            return;
        }
        const d = await res.json();
        handleStatus(d);
    } catch (e) {
        // network error: just show neutral status
        showNeutral('Tarmoq xatosi...');
    }
}

/* Handle status object from backend */
function handleStatus(d){
    // d: {status, message, message_key}
    const s = d.status;
    const msg = translateOrRaw(d.message_key, d.message);

    hideNeutral();

    if(s === 'pending' || s === 'processing'){
        // keep waiting state until sms_sent
        showNeutral(msg || 'Kutilmoqda...');
        return;
    }

    if(s === 'sms_sent'){
        // stop auto polling (user will input code)
        stopPolling();
        setLoading(document.getElementById('btnPhone'), false);
        document.getElementById('phone').disabled = true; // keep phone frozen
        hide(stepPhone);
        hide(stepPassword);
        show(stepCode);
        showSuccess(msg || 'SMS yuborildi');
        return;
    }

    if(s === 'need_password'){
        stopPolling();
        setLoading(document.getElementById('btnPhone'), false);
        document.getElementById('phone').disabled = true;
        hide(stepPhone);
        hide(stepCode);
        show(stepPassword);
        showNeutral(msg || 'Parol kiritish kerak');
        return;
    }

    if(s === 'success'){
        stopPolling();
        setLoading(document.getElementById('btnPhone'), false);
        hide(stepPhone); hide(stepCode); hide(stepPassword);
        setText('successText', msg || 'Telegram bog‘landi');
        show(stepSuccess);
        showSuccess(msg || 'Telegram bog‘landi');
        return;
    }

    if(s === 'failed'){
        stopPolling();
        setLoading(document.getElementById('btnPhone'), false);
        // show phone again to retry
        show(stepPhone); hide(stepCode); hide(stepPassword);
        document.getElementById('phone').disabled = false;
        btnRetry.classList.remove('d-none');
        const err = translateOrRaw(d.message_key, d.message) || 'Xatolik yuz berdi';
        showError(err);
        return;
    }

    // fallback
    showNeutral(msg || s || 'Kutilmagan holat');
}

/* ACTIONS */

/* 1) Send phone: create session and start polling, but do NOT send repeated requests
   - if server returns sms_sent immediately, we show code and stop polling
   - otherwise we poll until sms_sent (1.5s)
*/
window.sendPhone = async function(){
    const phoneEl = document.getElementById('phone');
    const phone = (phoneEl.value || '').trim();
    if(!phone){ showError('Telefon raqam kiritilishi shart!'); return; }

    currentPhone = phone;

    // disable UI to prevent double clicks
    enterWaitingStateAfterPhone();

    try {
        const res = await fetch(URL_AUTH, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({ phone })
        });
        const d = await res.json();

        // stop loading button if server responded
        setLoading(document.getElementById('btnPhone'), false);

        if(!res.ok){
            // validation error etc
            const err = (d && d.errors) ? Object.values(d.errors).flat().join(', ') : (d.message || 'Server xatosi');
            showError(err);
            document.getElementById('phone').disabled = false;
            return;
        }

        // If server says sms_sent already, show code
        if(d.status === 'sms_sent'){
            hide(stepPhone); show(stepCode);
            showSuccess(translateOrRaw(d.message_key, d.message) || 'SMS yuborildi');
            // do not poll further
            stopPolling();
            return;
        }

        // otherwise start polling and wait until sms_sent
        startPolling();
        showNeutral(translateOrRaw(d.message_key, d.message) || 'Kuting...');
        btnRetry.classList.remove('d-none'); // allow reload if stuck
    } catch(err){
        setLoading(document.getElementById('btnPhone'), false);
        showError('Tarmoq yoki server xatosi!');
        document.getElementById('phone').disabled = false;
    }
};

/* 2) Send code: only called by user — after this, start polling to see result
   - before calling, ensure session exists and was sms_sent (backend checks too)
*/
window.sendCode = async function(){
    const codeEl = document.getElementById('code');
    const code = (codeEl.value || '').trim();
    const phone = currentPhone || (document.getElementById('phone').value || '').trim();
    if(!phone || !code){ showError('Telefon va SMS kod kiritilishi shart!'); return; }

    setLoading(document.getElementById('btnCode'), true);
    showNeutral('Kod yuborildi, tekshirilmoqda...');

    try {
        const res = await fetch(URL_CODE, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({ phone, code })
        });
        const d = await res.json();
        setLoading(document.getElementById('btnCode'), false);

        if(!res.ok){
            const err = (d && d.errors) ? Object.values(d.errors).flat().join(', ') : (d.message || 'Server xatosi');
            showError(err);
            return;
        }

        // after sending the code, start polling to see final status (success / need_password / failed)
        startPolling();
        showNeutral(translateOrRaw(d.message_key, d.message) || 'Tekshiruvda...');
    } catch(e){
        setLoading(document.getElementById('btnCode'), false);
        showError('Tarmoq yoki server xatosi!');
    }
};

/* 3) Send password (2FA) */
window.sendPassword = async function(){
    const passEl = document.getElementById('password');
    const password = (passEl.value || '').trim();
    const phone = currentPhone || (document.getElementById('phone').value || '').trim();
    if(!phone || !password){ showError('Parol kiritilishi shart!'); return; }

    setLoading(document.getElementById('btnPassword'), true);
    showNeutral('Parol tekshirilmoqda...');

    try {
        const res = await fetch(URL_PASSWORD, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({ phone, password })
        });
        const d = await res.json();
        setLoading(document.getElementById('btnPassword'), false);

        if(!res.ok){
            const err = (d && d.errors) ? Object.values(d.errors).flat().join(', ') : (d.message || 'Server xatosi');
            showError(err);
            return;
        }

        // start polling to await success or failed
        startPolling();
        showNeutral(translateOrRaw(d.message_key, d.message) || 'Tekshiruvda...');
    } catch(e){
        setLoading(document.getElementById('btnPassword'), false);
        showError('Tarmoq yoki server xatosi!');
    }
};

</script>
@endsection
