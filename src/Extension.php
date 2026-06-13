<?php

namespace Convoro\Ext\Follow;

use App\Support\Settings;
use App\Support\Theme;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

/**
 * Follow & Feed — first-party Convoro extension.
 *
 * Members follow each other (a Follow button on profiles) and get a personal
 * feed at /feed of the latest topics and replies from the people they follow.
 */
class Extension extends ServiceProvider
{
    public function boot(): void
    {
        // Header-nav probe: is the viewer logged in (+ how many do they follow)?
        Route::middleware('web')->get('/api/ext/follow/me', function () {
            $in = Auth::check();

            return response()->json([
                'loggedIn' => $in,
                'count' => $in ? (int) DB::table('follows')->where('follower_id', Auth::id())->count() : 0,
            ]);
        });

        // Profile button state: can I follow this member, do I already, and their follower count.
        Route::middleware('web')->get('/api/ext/follow/state/{user}', function (int $user) {
            $me = Auth::id();
            $followers = (int) DB::table('follows')->where('followee_id', $user)->count();

            return response()->json([
                'canFollow' => $me !== null && $me !== $user,
                'following' => $me !== null && DB::table('follows')->where('follower_id', $me)->where('followee_id', $user)->exists(),
                'followers' => $followers,
            ]);
        });

        Route::middleware(['web', 'auth'])->group(function () {
            // Toggle following a member.
            Route::post('/api/ext/follow/user/{user}', function (int $user) {
                $me = Auth::id();
                abort_if($me === $user, 422, 'You cannot follow yourself.');
                abort_unless(DB::table('users')->where('id', $user)->exists(), 404);

                $q = DB::table('follows')->where('follower_id', $me)->where('followee_id', $user);
                if ($q->exists()) {
                    $q->delete();
                    $following = false;
                } else {
                    DB::table('follows')->insert(['follower_id' => $me, 'followee_id' => $user, 'created_at' => now()]);
                    $following = true;
                }

                return response()->json([
                    'following' => $following,
                    'followers' => (int) DB::table('follows')->where('followee_id', $user)->count(),
                ]);
            });

            // The member's personal feed.
            Route::get('/feed', fn () => response(self::page()));
        });
    }

    /** Recent topics + replies from the people the viewer follows, merged + sorted. */
    private static function feedItems(int $me): array
    {
        $followees = DB::table('follows')->where('follower_id', $me)->pluck('followee_id')->all();
        if (empty($followees)) {
            return [];
        }

        $users = DB::table('users')->whereIn('id', $followees)->pluck('name', 'id');

        $topics = DB::table('topics')
            ->whereIn('user_id', $followees)
            ->orderByDesc('created_at')->limit(30)
            ->get(['id', 'user_id', 'title', 'slug', 'created_at']);

        $replies = DB::table('posts')
            ->join('topics', 'topics.id', '=', 'posts.topic_id')
            ->whereIn('posts.user_id', $followees)
            ->where('posts.is_first', false)
            ->orderByDesc('posts.created_at')->limit(30)
            ->get([
                'posts.id as id', 'posts.user_id as user_id', 'posts.body_html as body_html',
                'posts.created_at as created_at', 'topics.title as title', 'topics.slug as slug',
            ]);

        $items = [];
        foreach ($topics as $t) {
            $items[] = [
                'type' => 'topic', 'user' => $users[$t->user_id] ?? 'Member', 'uid' => (int) $t->user_id,
                'title' => $t->title, 'url' => '/t/'.$t->slug, 'excerpt' => '',
                'at' => $t->created_at,
            ];
        }
        foreach ($replies as $r) {
            $items[] = [
                'type' => 'reply', 'user' => $users[$r->user_id] ?? 'Member', 'uid' => (int) $r->user_id,
                'title' => $r->title, 'url' => '/t/'.$r->slug.'#post-'.$r->id,
                'excerpt' => trim(Str::limit(strip_tags((string) $r->body_html), 200)),
                'at' => $r->created_at,
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, 40);
    }

    private static function page(): string
    {
        $theme = Theme::css();
        $font = Theme::fontStack((string) Settings::get('theme.font', 'Inter'));
        $mode = htmlspecialchars((string) Settings::get('theme.mode', 'light'), ENT_QUOTES);
        $name = htmlspecialchars((string) Settings::get('site.name', 'Convoro'), ENT_QUOTES);
        $csrf = csrf_token();
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $grads = [
            'linear-gradient(135deg,#f472b6,#db2777)', 'linear-gradient(135deg,#60a5fa,#2563eb)',
            'linear-gradient(135deg,#34d399,#059669)', 'linear-gradient(135deg,#fbbf24,#d97706)',
            'linear-gradient(135deg,#a78bfa,#7c3aed)', 'linear-gradient(135deg,#f87171,#dc2626)',
        ];

        $items = '';
        foreach (self::feedItems((int) Auth::id()) as $it) {
            $initials = strtoupper(Str::substr(trim($it['user']), 0, 1));
            $bg = $grads[($it['uid'] % 6)];
            $when = $it['at'] ? \Illuminate\Support\Carbon::parse($it['at'])->diffForHumans() : '';
            $verb = $it['type'] === 'topic' ? 'started a topic' : 'replied in';
            $items .= '<a class="it" href="'.$e($it['url']).'">'
                .'<span class="av" style="background:'.$bg.'">'.$e($initials).'</span>'
                .'<span class="body"><span class="meta"><b>'.$e($it['user']).'</b> '.$verb.' · '.$e($when).'</span>'
                .'<span class="t">'.$e($it['title']).'</span>'
                .($it['excerpt'] !== '' ? '<span class="ex">'.$e($it['excerpt']).'</span>' : '').'</span></a>';
        }
        if ($items === '') {
            $items = '<div class="empty">Your feed is empty. Follow members from their profile and their topics &amp; replies will show up here.</div>';
        }

        return <<<HTML
<!DOCTYPE html><html lang="en" data-theme="{$mode}"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{$csrf}"><title>Feed · {$name}</title>
<style>{$theme}
:root,html[data-theme="light"]{--c-bg:243 244 249;--c-surface:255 255 255;--c-surface-2:248 249 252;--c-border:230 232 240;--c-text:27 32 48;--c-text-2:74 81 104;--c-muted:138 144 166}
html[data-theme="dark"]{--c-bg:16 18 30;--c-surface:22 25 41;--c-surface-2:28 32 52;--c-border:42 47 70;--c-text:233 235 243;--c-text-2:174 180 208;--c-muted:120 127 152}
*{box-sizing:border-box}body{margin:0;font-family:{$font};background:rgb(var(--c-bg));color:rgb(var(--c-text))}
a{color:inherit;text-decoration:none}
.bar{display:flex;align-items:center;gap:12px;padding:14px 24px;border-bottom:1px solid rgb(var(--c-border));background:rgb(var(--c-surface))}
.bar b{font-weight:800}.bar .sp{flex:1}.bar .home{color:rgb(var(--c-primary));font-weight:700}
.wrap{max-width:680px;margin:0 auto;padding:32px 20px}
h1{font-size:26px;margin:0 0 4px}.sub{color:rgb(var(--c-muted));margin:0 0 24px}
.it{display:flex;align-items:flex-start;gap:12px;background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:var(--c-radius,12px);padding:14px 16px;margin-bottom:12px;transition:border-color .15s}
.it:hover{border-color:rgb(var(--c-primary))}
.av{flex:none;width:40px;height:40px;border-radius:999px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:15px}
.body{min-width:0;flex:1;display:flex;flex-direction:column;gap:3px}
.meta{font-size:12.5px;color:rgb(var(--c-muted))}.meta b{color:rgb(var(--c-text-2))}
.t{font-weight:700;font-size:16px;color:rgb(var(--c-text))}.it:hover .t{color:rgb(var(--c-primary))}
.ex{color:rgb(var(--c-muted));font-size:14px}
.empty{padding:60px;text-align:center;color:rgb(var(--c-muted));border:1px dashed rgb(var(--c-border));border-radius:var(--c-radius,12px)}
</style></head><body>
<div class="bar"><b>{$name}</b><span class="sp"></span><a class="home" href="/">← Community</a></div>
<div class="wrap"><h1>📰 Your feed</h1><p class="sub">The latest from members you follow.</p>
<div id="list">{$items}</div></div>
</body></html>
HTML;
    }
}
