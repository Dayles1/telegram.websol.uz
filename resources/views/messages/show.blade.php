@extends('layouts.app')

@section('content')
@php
use Illuminate\Support\Str;
use Carbon\Carbon;

$fullMessage = $group->message_text ?? $group->messages->first()?->message_text ?? '-';

// Static (doimiy) xabarlar
$staticMessages = [
    'flood_wait' => "Siz juda tez-tez xabar yubordingiz. Iltimos, biroz kutib, keyin qayta urinib ko‘ring.",
    'slowmode_wait' => "Guruh slowmode holatida — keyingi xabarni yuborish uchun ozgina kuting.",
    'SCHEDULE_TOO_MUCH' => "Jadvalga juda ko‘p xabar qo‘yilgan — rejalashtirishni kamaytiring yoki biroz kuting.",
    'chat_write_forbidden' => "Siz bu chatga xabar yozish huquqiga ega emassiz yoki yozish taqiqlangan.",
    'user_banned' => "Bu foydalanuvchi yoki kanal sizni bloklagan yoki akkauntingiz cheklangan.",
    'phone_code_expired' => "Telefon kodi muddati oʻtgan — qayta autentifikatsiya qiling.",
    'auth_key_invalid' => "Sessiya yoki autentifikatsiya kaliti noto‘g‘ri — sessiyani tiklang.",
    'network_error' => "Tarmoqda xato yuz berdi — internet ulanishingizni tekshiring yoki keyinroq urinib ko‘ring.",
    'peer_not_found' => "Ko‘rsatilgan chat yoki foydalanuvchi tizimda topilmadi.",
    'chat_guest_send_forbidden' => "Mehmon sifatida xabar yuborish taqiqlangan.",
    'session_missing' => "Sessiya fayli topilmadi — akkaunt sessiyasini qayta hosil qiling.",
    'session_start_failed' => "Sessiyani ishga tushirishda xato yuz berdi — server loglarini tekshiring.",
    'unknown_error' => "Noma'lum xato yuz berdi — server loglarini ko‘rib chiqing yoki administrator bilan bog‘laning.",
];

/**
 * Uz format: 1 kun 2 soat 3 daqiqa 4 soniya
 */
if (! function_exists('formatSecondsToReadable')) {
    function formatSecondsToReadable(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $days = intdiv($seconds, 86400);
        $seconds -= $days * 86400;

        $hours = intdiv($seconds, 3600);
        $seconds -= $hours * 3600;

        $minutes = intdiv($seconds, 60);
        $secs = $seconds - $minutes * 60;

        $parts = [];
        if ($days > 0) $parts[] = $days . ' kun';
        if ($hours > 0) $parts[] = $hours . ' soat';
        if ($minutes > 0) $parts[] = $minutes . ' daqiqa';
        if ($secs > 0 || empty($parts)) $parts[] = $secs . ' soniya';

        return implode(' ', $parts);
    }
}

/**
 * ErrorMessages — ArrayAccess obyekti qilib beramiz,
 * shunda Blade ichida $errorMessages[$key] sintaksisi o'zgartirilmasdan ishlaydi.
 */
if (! class_exists('ErrorMessagesArray')) {
    class ErrorMessagesArray implements ArrayAccess
    {
        protected array $static;

        public function __construct(array $static = [])
        {
            $this->static = $static;
        }

        public function offsetExists($offset): bool
        {
            $k = (string) $offset;
            if (isset($this->static[$k])) return true;

            $kNorm = str_replace('-', '_', $k);
            // flood_wait_1234 or flood_wait-1234
            if (preg_match('/^flood_wait(?:_|-)?\d+$/i', $kNorm)) return true;
            if (preg_match('/^slowmode_wait(?:_|-)?\d+$/i', $kNorm)) return true;

            // if prefix exists (fallback)
            if (isset($this->static[$kNorm])) return true;

            return false;
        }

        public function offsetGet($offset)
        {
            $k = (string) $offset;
            // direct match
            if (isset($this->static[$k])) return $this->static[$k];

            $kNorm = str_replace('-', '_', $k);

            if (isset($this->static[$kNorm])) return $this->static[$kNorm];

            // flood_wait_N
            if (preg_match('/^flood_wait(?:_|-)?(\d+)$/i', $kNorm, $m)) {
                $seconds = (int) $m[1];
                $time = formatSecondsToReadable($seconds);
                return "Telegram: siz juda ko‘p xabar yubordingiz. Iltimos, yana urinib ko‘rishdan avval {$time} kuting.";
            }

            // slowmode_wait_N
            if (preg_match('/^slowmode_wait(?:_|-)?(\d+)$/i', $kNorm, $m)) {
                $seconds = (int) $m[1];
                $time = formatSecondsToReadable($seconds);
                return "Guruh slowmode holatida — keyingi xabaringizni yuborish uchun {$time} kutishingiz kerak.";
            }

            // fallback: agar prefix mavjud bo'lsa (masalan flood_wait ga mos kelmasa ham)
            if (preg_match('/^([a-z_]+)(?:_|-)?(\d*)$/i', $kNorm, $m)) {
                $prefix = $m[1] ?? null;
                if ($prefix && isset($this->static[$prefix])) {
                    // agar raqam bo'lsa, qo'shimcha ma'lumot qo'shish mumkin
                    $num = $m[2] ?? null;
                    if ($num) {
                        return $this->static[$prefix] . " (kod: {$num})";
                    }
                    return $this->static[$prefix];
                }
            }

            // oxirgi variant: uni o'zi qaytaramiz (Blade tarafda ?? $fk ishlayveradi)
            return null;
        }

        public function offsetSet($offset, $value): void
        {
            // allow dynamic override
            $this->static[(string) $offset] = $value;
        }

        public function offsetUnset($offset): void
        {
            unset($this->static[(string) $offset]);
        }
    }
}

// end result — foydalanuvchi uchun ishlatishdagi nom bir xil bo'lib qoladi
$errorMessages = new ErrorMessagesArray($staticMessages);
@endphp


<div class="container py-4">
    <div id="flashMsgContainer"></div>

    <div class="mb-3 d-flex justify-content-between align-items-start">
        <div>
            <h4 class="mb-1">Xabar guruhi #{{ $group->id }}</h4>
            <div class="small text-muted">
                Foydalanuvchi: <strong>{{ $group->phone->user->name ?? '-' }}</strong>
                &nbsp; • &nbsp; Telefon: <strong>{{ $group->phone->phone ?? '-' }}</strong>
            </div>
        </div>

        {{-- Overall status counts --}}
        <div class="text-end">
            <div class="small text-muted">Umumiy statuslar</div>
            <div>
                <span class="badge bg-success">Yub: {{ $totals['sent'] }}</span>
                <span class="badge bg-danger">Fail: {{ $totals['failed'] }}</span>
                <span class="badge bg-warning text-dark">Kut: {{ $totals['pending'] }}</span>
                <span class="badge bg-dark">Bekor: {{ $totals['canceled'] }}</span>
            </div>

            {{-- overall segmented progress --}}
            <div class="progress mt-2" style="height:12px; width:220px; border-radius:10px; overflow:hidden;">
                <div class="progress-bar bg-success" role="progressbar" style="width: {{ $percentages['sent'] }}%;"></div>
                <div class="progress-bar bg-danger" role="progressbar" style="width: {{ $percentages['failed'] }}%;"></div>
                <div class="progress-bar bg-warning" role="progressbar" style="width: {{ $percentages['pending'] }}%;"></div>
                @if($percentages['canceled'] > 0)
                    <div class="progress-bar" style="background:#000; width: {{ $percentages['canceled'] }}%;"></div>
                @endif
            </div>
        </div>
    </div>

    {{-- Full message preview --}}
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <strong>To'liq xabar</strong>
                <div class="small text-muted">Guruhga yuborilayotgan matn (preview)</div>
            </div>

            <div class="d-flex gap-2">
                <button id="copyMessageBtn" class="btn btn-sm btn-outline-primary">📋 Nusxalash</button>
                <button id="toggleFullMessageBtn" class="btn btn-sm btn-outline-secondary" data-expanded="false">🔍 Kattalashtirish</button>
            </div>
        </div>

        <div class="border rounded bg-white">
            <pre id="fullMessageText" class="p-3 mb-0" style="white-space:pre-wrap; word-break:break-word; max-height:180px; overflow:auto;">{{ $fullMessage }}</pre>
            <div id="fullMessageOverlay" style="position:relative; height:44px; margin-top:-44px; background:linear-gradient(transparent, rgba(255,255,255,0.95));"></div>
        </div>
    </div>

    {{-- Peers compact list (one-row each) --}}
    <div class="list-group">
        @foreach($peersGrouped as $p)
            @php
                $status = $p->status;
                // status dot color
                $dotColor = match($status) {
                    'sent' => 'status-dot--success',
                    'failed' => 'status-dot--danger',
                    'pending' => 'status-dot--warning',
                    'canceled' => 'status-dot--canceled',
                    default => 'status-dot--unknown',
                };
                $statusLabel = $status === 'sent' ? 'Yuborilgan' : ($status === 'failed' ? 'Muvaffaqiyatsiz' : ($status === 'pending' ? 'Kutilmoqda' : ($status === 'canceled' ? 'Bekor' : '—')));
                $sentAt = $p->sent_at ? \Carbon\Carbon::parse($p->sent_at)->format('d.m.Y H:i') : '—';
                $errorKeys = $p->failed_keys ?? [];
            @endphp

            <div class="list-group-item d-flex align-items-center justify-content-between gap-3 peer-row" data-peer="{{ e($p->peer) }}" style="margin-bottom:3px;">
                {{-- Left: status dot + peer label + link --}}
                <div style="min-width:220px; max-width:45%; overflow:hidden;">
                    <div class="d-flex align-items-center gap-2">
                        <span class="status-dot {{ $dotColor }}" title="{{ $statusLabel }}"></span>
                        <div class="fw-semibold text-truncate" style="max-width:260px;">{{ $p->peer }}</div>

                        @if($p->tg_link)
                            <a href="{{ $p->tg_link }}" target="_blank" class="small" title="Open in Telegram">🔗</a>
                        @endif
                    </div>

                    {{-- small caption: last sent --}}
                    <div class="small text-muted" style="margin-left:20px;">Vaqt: <strong>{{ $sentAt }}</strong></div>
                </div>

                {{-- middle: compact (no long progress) --}}
                <div class="flex-grow-1 px-2">
                    <div class="d-flex justify-content-between mb-1">
                        <div class="small text-muted">{{ $statusLabel }}</div>
                        {{-- show first error message (if any) --}}
                        <div class="small text-danger">
                            @if(count($errorKeys) > 0)
                                {{ $errorMessages[$errorKeys[0]] ?? $errorKeys[0] }}
                            @else
                                &nbsp;
                            @endif
                        </div>
                    </div>
                    {{-- optional 2cm line indicator (hidden by default) --}}
                    <div class="status-line-wrap">
                        <span class="status-line {{ $dotColor }}"></span>
                    </div>
                </div>

                {{-- actions --}}
                <div style="min-width:170px" class="d-flex gap-2 align-items-center justify-content-end">
                    @if($p->tg_link)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $p->tg_link }}" target="_blank">Telegram</a>
                    @endif

                    <button class="btn btn-sm btn-outline-secondary btn-copy-peer" data-peer="{{ e($p->peer) }}">Nusxalash</button>

                    @if($p->in_catalog && $p->catalog_id)
                        <button class="btn btn-sm btn-danger btn-remove-peer" data-catalog-id="{{ $p->catalog_id }}" data-peer="{{ e($p->peer) }}" data-user-phone-id="{{ $group->phone->id }}" data-group-id="{{ $group->id }}">Olib tashlash</button>
                    @else
                        <button class="btn btn-sm btn-outline-secondary" disabled>Catalogda yo'q</button>
                    @endif
                </div>

                {{-- collapsed details (full width) --}}
                <div class="w-100 mt-2 peer-details collapse">
                    <div class="small text-muted">
                        <strong>Xato(lar):</strong>
                        @if(count($errorKeys) === 0)
                            <span class="text-muted">Yo‘q</span>
                        @else
                            <ul class="mb-0">
                                @foreach($errorKeys as $fk)
                                    <li>{{ $errorMessages[$fk] ?? $fk }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<!-- Confirm modal (centered) -->
<div id="confirmModal" class="modal" tabindex="-1" style="display:none;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow">
      <div class="modal-header">
        <h5 class="modal-title">Katalogdan olib tashlashni tasdiqlang</h5>
        <button type="button" class="btn-close" id="confirmCloseBtn" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="confirmModalText">Siz <strong id="confirmPeer"></strong> ni katalogdan olib tashlamoqchisiz.</p>
        <div class="text-muted small">Bu amal katalogdan elementni olib tashlaydi.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-outline-secondary" id="confirmCancelBtn">Bekor qilish</button>
        <button class="btn btn-sm btn-danger" id="confirmOkBtn">Ha, olib tashlash</button>
      </div>
    </div>
  </div>
</div>

<style>
    /* tiny spacing between peers (~1mm) */
    .peer-row { border-left: 4px solid transparent; padding-top: 6px; padding-bottom: 6px; margin-bottom: 3px; }
    .peer-row:hover { background:#fbfbfc; border-left-color:#6c757d22; }

    /* status dot (mini) */
    .status-dot { width:12px; height:12px; border-radius:50%; display:inline-block; box-shadow:0 0 0 1px rgba(0,0,0,0.05) inset; }
    .status-dot--success { background:#198754; }   /* green */
    .status-dot--danger { background:#dc3545; }    /* red */
    .status-dot--warning { background:#ffc107; }   /* yellow */
    .status-dot--canceled { background:#000; }     /* black */
    .status-dot--unknown { background:#dcdcdc; }   /* light gray */

    /* optional thin 2cm line indicator */
    .status-line-wrap { margin-top:6px; }
    .status-line { display:inline-block; width:2cm; height:6px; border-radius:4px; }
    .status-line--success { background:#198754; }
    .status-line--danger { background:#dc3545; }
    .status-line--warning { background:#ffc107; }
    .status-line--canceled { background:#000; }
    .status-line--unknown { background:#dcdcdc; }

    .peer-details { padding:.5rem 1rem; background:#fafafa; border-left:4px solid #eee; margin-top:.5rem; display:none; }
    @media (max-width:768px) {
        .peer-row { flex-direction:column; align-items:flex-start; gap:.5rem; }
    }

    /* Modal basic styles */
    .modal { position:fixed; inset:0; z-index:1050; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); }
    .modal .modal-dialog { max-width:420px; }
    .modal .modal-content { border-radius:8px; overflow:hidden; }
    .modal[style*="display:none"]{ display:none !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // flash helper
    function showFlash(msg, type='success') {
        const container = document.getElementById('flashMsgContainer');
        const el = document.createElement('div');
        el.className = `alert alert-${type} alert-dismissible`;
        el.style.marginBottom = '8px';
        el.innerHTML = `${msg} <button type="button" class="btn-close" data-dismiss="alert">×</button>`;
        container.prepend(el);
        setTimeout(()=> el.remove(), 6000);
    }

    // copy full message
    document.getElementById('copyMessageBtn')?.addEventListener('click', async function () {
        const text = document.getElementById('fullMessageText')?.innerText.trim() || '';
        try { await navigator.clipboard.writeText(text); showFlash('Xabar nusxalandi', 'success'); }
        catch { showFlash('Nusxalash imkoni yo‘q', 'danger'); }
    });

    // toggle full message
    document.getElementById('toggleFullMessageBtn')?.addEventListener('click', function () {
        const pre = document.getElementById('fullMessageText');
        const overlay = document.getElementById('fullMessageOverlay');
        const expanded = this.getAttribute('data-expanded') === 'true';
        if (!expanded) { pre.style.maxHeight = 'none'; overlay.style.display='none'; this.setAttribute('data-expanded','true'); this.innerText='🔎 Kichraytirish'; }
        else { pre.style.maxHeight='180px'; overlay.style.display='block'; this.setAttribute('data-expanded','false'); this.innerText='🔍 Kattalashtirish'; }
    });

    // copy peer
    document.querySelectorAll('.btn-copy-peer').forEach(btn => {
        btn.addEventListener('click', async function () {
            const peer = this.dataset.peer;
            try { await navigator.clipboard.writeText(peer); showFlash('Peer nusxalandi','success'); }
            catch { showFlash('Nusxalash imkoni yo‘q','danger'); }
        });
    });

    // toggle details (simple)
    document.querySelectorAll('.btn-toggle-details').forEach(btn => {
        btn.addEventListener('click', function () {
            const li = this.closest('.list-group-item');
            const details = li.querySelector('.peer-details');
            if (details) details.style.display = (details.style.display === 'block') ? 'none' : 'block';
        });
    });

    // Confirm modal logic (same as before)
    const confirmModal = document.getElementById('confirmModal');
    const confirmPeerEl = document.getElementById('confirmPeer');
    const confirmOkBtn = document.getElementById('confirmOkBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const confirmCloseBtn = document.getElementById('confirmCloseBtn');

    let currentRemoveButton = null;
    let pendingPayload = null;

    document.querySelectorAll('.btn-remove-peer').forEach(btn => {
        btn.addEventListener('click', function () {
            currentRemoveButton = btn;
            pendingPayload = {
                catalog_id: btn.dataset.catalogId,
                peer: btn.dataset.peer,
                
            };
            confirmPeerEl.innerText = pendingPayload.peer;
            confirmModal.style.display = 'flex';
        });
    });

    [confirmCancelBtn, confirmCloseBtn].forEach(el => el?.addEventListener('click', function () {
        confirmModal.style.display = 'none';
        currentRemoveButton = null;
        pendingPayload = null;
    }));

    confirmOkBtn?.addEventListener('click', function () {
        if (!pendingPayload || !currentRemoveButton) { confirmModal.style.display='none'; return; }

        currentRemoveButton.disabled = true;
        const origText = currentRemoveButton.innerText;
        currentRemoveButton.innerText = 'Jarayonda...';

        fetch('{{ route('catalogs.removePeer') }}', {
            method: 'POST',
            headers: {
                'Content-Type':'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept':'application/json'
            },
            body: JSON.stringify(pendingPayload)
        })
        .then(r => r.json())
        .then(data => {
            confirmModal.style.display = 'none';
            if (data?.status === 'success') {
                currentRemoveButton.remove();
                showFlash(data.message ?? 'Peer muvaffaqiyatli olib tashlandi', 'success');
            } else {
                showFlash(data?.message ?? 'Xato yuz berdi', 'danger');
                currentRemoveButton.disabled = false;
                currentRemoveButton.innerText = origText;
            }
        })
        .catch(err => {
            console.error(err);
            showFlash('Server bilan bog‘lanishda xato. Konsolni tekshiring.', 'danger');
            confirmModal.style.display = 'none';
            currentRemoveButton.disabled = false;
            currentRemoveButton.innerText = origText;
        });
    });

    // init bootstrap tooltips if present
    if (typeof bootstrap !== 'undefined') {
        var tlist = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tlist.map(function (el) { return new bootstrap.Tooltip(el); });
    }
});
</script>
@endsection
