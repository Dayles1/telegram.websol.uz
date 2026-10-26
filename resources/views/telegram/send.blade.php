@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-center align-items-center" style="min-height:80vh; background:#f0f2f5;">
    <div class="card shadow-lg p-4 rounded-4" style="max-width:480px; width:100%;">
        <h3 class="text-center mb-4 fw-bold">Telegram Xabar Yuborish</h3>

        <!-- Success/Error -->
        <div id="successMsg" class="alert alert-success d-none"></div>
        <ul id="errorList" class="list-group mb-3 d-none"></ul>

        <!-- Form -->
        <div class="mb-3">
            <label class="form-label fw-semibold">Yuboruvchi raqami</label>
            <input type="text" id="phone" class="form-control" value="{{ $phone ?? '' }}" readonly>
            <small class="text-muted">Telefon avtomatik to‘ldiriladi</small>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Recipient (peer)</label>
            <input type="text" id="user_id" class="form-control" placeholder="123456789 yoki @username">
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Xabar matni</label>
            <textarea id="message" class="form-control" rows="4" placeholder="Xabar matnini kiriting"></textarea>
        </div>

        <button id="btnSend" class="btn btn-primary w-100 py-2" onclick="sendMsg()">Yuborish</button>
    </div>
</div>

<style>
textarea { resize:none; }
.list-group-item { border:none; padding:.4rem .8rem; font-size:.875rem; border-radius:.5rem; }
.list-group-item-danger { background:#f8d7da; color:#842029; }
.spinner { width:18px; height:18px; border:3px solid #fff; border-top-color:transparent; border-radius:50%; display:inline-block; animation:spin .6s linear infinite; margin-right:5px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
function showErrors(errors){
    const ul = document.getElementById('errorList');
    ul.innerHTML='';
    if(!errors || Object.keys(errors).length===0){ ul.classList.add('d-none'); return; }
    for(const key in errors){
        errors[key].forEach(msg=>{
            let li=document.createElement('li');
            li.textContent=msg;
            li.className='list-group-item list-group-item-danger';
            ul.appendChild(li);
        });
    }
    ul.classList.remove('d-none');
}

function showSuccess(msg){
    const el=document.getElementById('successMsg');
    el.innerText=msg;
    el.classList.remove('d-none');
    setTimeout(()=>el.classList.add('d-none'),3000);
}

function loading(btn,state){
    if(state){ btn.dataset.original=btn.innerHTML; btn.innerHTML=`<span class="spinner"></span> Yuborilmoqda...`; btn.disabled=true; }
    else{ btn.innerHTML=btn.dataset.original; btn.disabled=false; }
}

function sendMsg(){
    const btn=document.getElementById('btnSend');
    loading(btn,true);
    showErrors(null);

    fetch('/tg/send',{
        method:'POST',
        headers:{ 'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}' },
        body:JSON.stringify({
            phone:document.getElementById('phone').value,
            user_id:document.getElementById('user_id').value,
            message:document.getElementById('message').value
        })
    })
    .then(async r=>{
        loading(btn,false);
        let d;
        try{ d=await r.json(); } catch(e){ showErrors({'json':['Server JSON parse failed']}); return; }

        if(r.status===422 && d.errors){ showErrors(d.errors); }
        else if(d.status==='message_sent_in_background'){ 
            showSuccess("Xabar muvaffaqiyatli yuborildi!");
            document.getElementById('user_id').value='';
            document.getElementById('message').value='';
        } else if(d.error){ showErrors({'server':[d.error]}); }
        else{ showErrors({'unknown':['Xabar yuborilmadi']}); }
    })
    .catch(err=>{ loading(btn,false); showErrors({'network':['Serverga ulanishda xatolik']}); console.error(err); });
}
</script>
@endsection
