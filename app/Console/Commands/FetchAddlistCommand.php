<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use App\Models\UserPhone;
use danog\MadelineProto\API;

class FetchAddlistCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     *  php artisan telegram:addlist-fetch {userPhoneId} {addlistUrl}
     */
    protected $signature = 'telegram:addlist-fetch {userPhoneId} {addlistUrl}';

    protected $description = 'Fetch peers from a t.me/addlist/... link using a stored user phone/session and log them as one big string';

    public function handle()
    {
        $userPhoneId = (int) $this->argument('userPhoneId');
        $addlistUrl = (string) $this->argument('addlistUrl');

        // 1) load phone record
        $phone = UserPhone::find($userPhoneId);
        if (! $phone) {
            $this->error("UserPhone with id={$userPhoneId} not found.");
            return 1;
        }

        $phoneNumber = $phone->phone ?? 'unknown';
        $this->info("Using phone id={$userPhoneId} phone={$phoneNumber}");

        // 2) extract slug from addlist url
        $slug = null;
        if (preg_match('#(?:t\.me\/addlist\/|tg:\/\?addlist=|tg:\/addlist\?slug=|tg:\/addlist\?)([A-Za-z0-9_\-]+)#', $addlistUrl, $m)) {
            $slug = $m[1];
        } elseif (preg_match('#addlist\/([A-Za-z0-9_\-]+)#', $addlistUrl, $m2)) {
            $slug = $m2[1];
        }
        if (! $slug) {
            // try query string "slug=" 
            $u = parse_url($addlistUrl);
            if (!empty($u['query'])) {
                parse_str($u['query'], $q);
                if (!empty($q['slug'])) $slug = $q['slug'];
            }
        }

        if (! $slug) {
            $this->error("Could not extract addlist slug from provided URL: {$addlistUrl}");
            return 1;
        }

        $this->info("Extracted slug: {$slug}");

        // 3) start MadelineProto with session path
        $sessionPath = $phone->session_path ?? null;
        if (! $sessionPath || ! File::exists($sessionPath)) {
            $this->error("Session path not found for this phone. session_path={$sessionPath}");
            return 1;
        }

        try {
            $Madeline = new API($sessionPath);
            $Madeline->start();
        } catch (\Throwable $e) {
            Log::error("MadelineProto start failed for phone id={$userPhoneId}", ['error' => $e->getMessage()]);
            $this->error("MadelineProto start failed: " . $e->getMessage());
            return 1;
        }

        // 4) call checkChatlistInvite to get chatlist info
        try {
            $res = $Madeline->chatlists->checkChatlistInvite(['slug' => $slug]);
        } catch (\Throwable $e) {
            Log::error("checkChatlistInvite failed for slug={$slug}", ['error' => $e->getMessage()]);
            $this->error("checkChatlistInvite failed: " . $e->getMessage());
            return 1;
        }

        // 5) build maps for users and chats (id => username/title)
        $usersMap = [];
        if (!empty($res['users']) && is_array($res['users'])) {
            foreach ($res['users'] as $u) {
                $id = $u['id'] ?? null;
                if ($id) {
                    $username = $u['username'] ?? null;
                    if (! $username) {
                        $username = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                        if ($username === '') $username = 'user' . $id;
                    } else {
                        $username = '@' . ltrim($username, '@');
                    }
                    $usersMap[$id] = $username;
                }
            }
        }

        $chatsMap = [];
        if (!empty($res['chats']) && is_array($res['chats'])) {
            foreach ($res['chats'] as $c) {
                $id = $c['id'] ?? null;
                if ($id) {
                    $username = $c['username'] ?? null;
                    if ($username) {
                        $chatsMap[$id] = '@' . ltrim($username, '@');
                    } else {
                        $title = $c['title'] ?? ('chat' . $id);
                        $chatsMap[$id] = $title;
                    }
                }
            }
        }

        // 6) resolve peers vector (the peer entries may be 'Peer' objects or various inputPeer forms)
        $results = [];

        if (!empty($res['peers']) && is_array($res['peers'])) {
            foreach ($res['peers'] as $p) {
                // p can be an array with keys like 'user_id', 'chat_id', 'channel_id', or be an integer
                $resolved = null;

                if (is_array($p)) {
                    // common keys to check
                    if (isset($p['user_id'])) {
                        $uid = $p['user_id'];
                        $resolved = $usersMap[$uid] ?? ('user' . $uid);
                    } elseif (isset($p['channel_id'])) {
                        $cid = $p['channel_id'];
                        $resolved = $chatsMap[$cid] ?? ('channel' . $cid);
                    } elseif (isset($p['chat_id'])) {
                        $cid = $p['chat_id'];
                        $resolved = $chatsMap[$cid] ?? ('chat' . $cid);
                    } elseif (isset($p['id']) && is_int($p['id'])) {
                        // sometimes peer is just object with id
                        $id = $p['id'];
                        $resolved = $usersMap[$id] ?? $chatsMap[$id] ?? 'peer' . $id;
                    } else {
                        // fallback: try to stringify useful keys
                        if (isset($p['_'])) {
                            $resolved = $p['_'];
                        } else {
                            $resolved = json_encode($p, JSON_UNESCAPED_UNICODE);
                        }
                    }
                } else {
                    // scalar peer (maybe integer)
                    if (is_int($p) || ctype_digit((string)$p)) {
                        $id = (int)$p;
                        $resolved = $usersMap[$id] ?? $chatsMap[$id] ?? 'peer' . $id;
                    } else {
                        $resolved = (string)$p;
                    }
                }

                if ($resolved) $results[] = $resolved;
            }
        }

        // 7) also include any chats/users directly present (dedupe)
        // merge chatsMap and usersMap values
        foreach ($chatsMap as $c) $results[] = $c;
        foreach ($usersMap as $u) $results[] = $u;

        // unique & preserve order
        $final = array_values(array_unique($results));

        // build big string
        $bigString = "Phone: {$phoneNumber}\nAddlist: {$addlistUrl}\nSlug: {$slug}\nPeers (" . count($final) . "):\n" . implode(', ', $final);

        // log to laravel log and output to console
        Log::info("FetchAddlistCommand result for phone_id={$userPhoneId}: " . $bigString);
        $this->line($bigString);

        $this->info("Done. Results logged to laravel.log");

        return 0;
    }
}
