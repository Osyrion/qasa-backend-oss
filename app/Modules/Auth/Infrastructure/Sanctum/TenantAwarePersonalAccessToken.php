<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Sanctum;

use App\Modules\Shared\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * personal_access_tokens is tenant-scoped now (docs/plans/POSTGRES_RLS_PLAN.md,
 * phase 7), and Sanctum resolves it on every authenticated request before
 * anything is bound — that's the whole point of the guard. Stock
 * findToken() does `static::find($id)` for the `id|token` shape this app
 * issues; from an unbound connection that finds nothing, even though $id
 * isn't secret — it's meaningless without the plaintext half findToken()
 * still has to check.
 *
 * account_for_token() is the same narrow SECURITY DEFINER lookup every
 * other pre-bind read in this project uses: an id in, an account id out,
 * never the row. It returns NULL for a token that isn't a tenant user's at
 * all — Admin's own tokens, which need no binding because the Admin
 * migration's carve-out makes them unconditionally visible.
 */
class TenantAwarePersonalAccessToken extends PersonalAccessToken
{
    // Eloquent guesses the table from the class name, and this class isn't
    // called PersonalAccessToken — without this it would look for
    // tenant_aware_personal_access_tokens instead of the real table.
    protected $table = 'personal_access_tokens';

    public static function findToken($token)
    {
        if (str_contains((string) $token, '|')) {
            [$id] = explode('|', (string) $token, 2);

            /** @var object{account: string|null}|null $row */
            $row = DB::selectOne('SELECT public.account_for_token(?) AS account', [$id]);

            if ($row?->account !== null) {
                TenantContext::set($row->account);
            }
        }

        return parent::findToken($token);
    }
}
