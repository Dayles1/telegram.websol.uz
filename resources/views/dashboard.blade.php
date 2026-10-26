@extends('layouts.app')

@section('content')

    {{-- 1️⃣ Mening telefonlarim --}}
    <div class="header-row mb-3 ">
        <h3>Mening Telegram telefonlarim</h3>
    </div>

    @if ($myPhones->isEmpty())
        <div class="py-4 text-center text-muted">Telefonlar mavjud emas. Yangi telefon qo‘shing.</div>
    @else
        <div class="table-responsive mb-5 ">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th>Foydalanuvchi</th> <!-- Admin uchun ko‘rinadi, oddiy user bo‘lsa bo‘sh -->
                        <th>Telefon</th>
                        <th>Qo‘shilgan sana</th>
                        <th class="text-end">Amallar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($myPhones as $phone)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td></td> {{-- Oddiy user uchun Foydalanuvchi ustuni bo‘sh --}}
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="phone-badge">{{ $phone->phone }}</span>
                                    <button class="btn btn-sm btn-outline-secondary"
                                        onclick="copyPhone('{{ $phone->phone }}')">📋 Nusxa</button>
                                </div>
                            </td>
                            <td>{{ $phone->created_at->format('d.m.Y H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('telegram.send-messages-form', ['phone' => $phone->phone]) }}"
                                    class="btn btn-primary btn-sm">
                                    ✉ Xabar jo‘natish
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

            </table>
        </div>
    @endif

    {{-- 2️⃣ Superadmin uchun boshqa foydalanuvchilar --}}
    @if ($allPhones->isNotEmpty())
        <div class="header-row mb-3">
            {{-- <h3>Boshqa foydalanuvchilar Telegram telefonlari</h3> --}}
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th>Foydalanuvchi</th>
                        <th>Telefon</th>
                        <th>Qo‘shilgan sana</th>
                        <th class="text-end">Amallar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($allPhones as $phone)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                <a href="javascript:void(0)" class="btn btn-sm btn-outline-secondary">
                                    {{ $phone->user->name ?? '-' }}
                                </a>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="phone-badge">{{ $phone->phone }}</span>
                                    <button class="btn btn-sm btn-outline-secondary"
                                        onclick="copyPhone('{{ $phone->phone }}')">📋 Nusxa</button>
                                </div>
                            </td>
                            <td>{{ $phone->created_at->format('d.m.Y H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('telegram.send-messages-form', ['phone' => $phone->phone]) }}"
                                    class="btn btn-primary btn-sm">
                                    ✉ Xabar jo‘natish
                                </a>
                            </td>
                        </tr>
                    @endforeach

                </tbody>
            </table>
        </div>
    @endif

    <script>
        function copyPhone(number) {
            navigator.clipboard.writeText(number)
                .then(() => alert('Nomerni nusxa olindi: ' + number))
                .catch(() => alert('Nusxalashda xatolik'));
        }
    </script>

@endsection
