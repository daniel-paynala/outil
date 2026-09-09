<?php

namespace App\Modules\Phones\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne téléphonique de l'entreprise — une SIM, et ce qui y est rattaché.
 *
 * Le registre porte des numéros liés à Airtel Money. Rien d'assez secret pour
 * le coffre : un numéro se lit sur une facture, et le protéger derrière un
 * droit n'aurait fait que pousser l'équipe à en garder une copie ailleurs.
 * Ce qui compte ici est que la copie soit unique et datée, pas cachée.
 */
class PhoneLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'operator',
        'number',
        'registered_name',
        'purpose',
        'mobile_money',
        'whatsapp',
        'whatsapp_note',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'mobile_money' => 'boolean',
            'whatsapp' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
