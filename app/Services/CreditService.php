<?php

namespace App\Services;

use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\UserCredit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billetera de créditos del SaaS.
 *
 * Multiempresa: el saldo pertenece a la EMPRESA. Los métodos conservan la firma
 * por user_id (el webhook de pagos y los controladores no cambian): internamente
 * se resuelve la empresa del usuario y el saldo se comparte entre todo su equipo.
 * Si el usuario aún no tiene empresa (transición), opera por user_id como antes.
 */
class CreditService
{
    /** Fila de saldo de la empresa (o del usuario en modo legado), con lock. */
    private function filaSaldo(int $userId, string $module): UserCredit
    {
        $user = User::withTrashed()->find($userId);
        $empresaId = $user?->empresaId();

        if ($empresaId) {
            $uc = UserCredit::where('empresa_id', $empresaId)->where('module', $module)->lockForUpdate()->first();
            if (! $uc) {
                // Adopta una fila legada del dueño si existe; si no, crea una nueva.
                $ownerId = $user->billingOwner()->id;
                $uc = UserCredit::whereNull('empresa_id')->where('user_id', $ownerId)
                    ->where('module', $module)->lockForUpdate()->first();
                if ($uc) {
                    $uc->empresa_id = $empresaId;
                    $uc->save();
                } else {
                    $uc = UserCredit::create([
                        'user_id' => $ownerId,
                        'empresa_id' => $empresaId,
                        'module' => $module,
                        'credits' => 0,
                    ]);
                }
            }
            return $uc;
        }

        // Modo legado (usuario sin empresa).
        $uc = UserCredit::where('user_id', $userId)->where('module', $module)->lockForUpdate()->first();
        return $uc ?? UserCredit::create(['user_id' => $userId, 'module' => $module, 'credits' => 0]);
    }

    /** Acredita créditos (a la empresa del usuario) de forma atómica y registra la transacción. */
    public function creditUser(int $userId, string $module, int $credits, ?int $paymentTxId = null, ?int $packageId = null, ?string $description = null): UserCredit
    {
        return DB::transaction(function () use ($userId, $module, $credits, $paymentTxId, $packageId, $description) {
            $uc = $this->filaSaldo($userId, $module);

            $uc->credits = (int) $uc->credits + (int) $credits;
            $uc->save();

            CreditTransaction::create([
                'user_id' => $userId,
                'empresa_id' => $uc->empresa_id,
                'module' => $module,
                'change' => $credits,
                'balance_after' => $uc->credits,
                'type' => 'purchase',
                'credit_package_id' => $packageId,
                'payment_transaction_id' => $paymentTxId,
                'description' => $description,
            ]);

            if ($paymentTxId) {
                try {
                    $pt = PaymentTransaction::find($paymentTxId);
                    if ($pt) {
                        $pt->user_id = $userId;
                        $pt->empresa_id = $uc->empresa_id;
                        $pt->save();
                    }
                } catch (ModelNotFoundException $e) {
                    // ignore
                }
            }

            return $uc;
        });
    }

    /** Consume créditos de la empresa; lanza ValidationException si no hay saldo. */
    public function consume(int $userId, string $module, int $amount = 1): CreditTransaction
    {
        return DB::transaction(function () use ($userId, $module, $amount) {
            $uc = $this->filaSaldo($userId, $module);
            $current = (int) $uc->credits;

            if ($current < $amount) {
                throw ValidationException::withMessages(['credits' => ['Saldo insuficiente de créditos para este módulo.']]);
            }

            $uc->credits = $current - $amount;
            $uc->save();

            return CreditTransaction::create([
                'user_id' => $userId,
                'empresa_id' => $uc->empresa_id,
                'module' => $module,
                'change' => -$amount,
                'balance_after' => $uc->credits,
                'type' => 'consumption',
                'description' => "Consume {$amount} credit(s)",
            ]);
        });
    }

    /** Saldo actual por módulo de la empresa del usuario (para /credits y /me). */
    public function saldos(User $user): array
    {
        $empresaId = $user->empresaId();
        $q = $empresaId
            ? UserCredit::where('empresa_id', $empresaId)
            : UserCredit::where('user_id', $user->id);

        return $q->pluck('credits', 'module')->map(fn ($c) => (int) $c)->all();
    }
}
