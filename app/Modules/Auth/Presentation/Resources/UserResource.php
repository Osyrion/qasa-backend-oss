<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Resources;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/**
 * @property User $resource
 */

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string', example: 'Ing.', nullable: true),
        new OA\Property(property: 'name', type: 'string', example: 'Ján'),
        new OA\Property(property: 'surname', type: 'string', example: 'Novák'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Ján Novák'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jan@example.com'),
        new OA\Property(property: 'phone', type: 'string', example: '+421 900 123 456', nullable: true),
        new OA\Property(property: 'avatar_path', type: 'string', nullable: true),
        new OA\Property(property: 'color', type: 'string', example: '#FF5733', nullable: true),
        new OA\Property(property: 'ico', type: 'string', example: '12345678', nullable: true),
        new OA\Property(property: 'dic', type: 'string', example: '1234567890', nullable: true),
        new OA\Property(property: 'is_vat_payer', type: 'boolean', example: true, nullable: true, description: 'Deprecated, use vat_status'),
        new OA\Property(property: 'vat_status', type: 'string', enum: ['non_payer', 'identified', 'payer'], example: 'payer'),
        new OA\Property(property: 'tax_flat_rate', type: 'integer', example: 20, nullable: true),
        new OA\Property(property: 'default_currency', type: 'string', example: 'EUR', nullable: true),
        new OA\Property(property: 'invoice_prefix', type: 'string', example: 'FA', nullable: true),
        new OA\Property(property: 'invoice_number_mask', type: 'string', example: '{YYYY}{NNNN}', nullable: true),
        new OA\Property(property: 'invoice_number_start', type: 'integer', example: 1, nullable: true),
        new OA\Property(property: 'locale', type: 'string', example: 'sk', nullable: true),
        new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true),
        new OA\Property(property: 'address', type: 'string', example: 'Hlavná 1', nullable: true),
        new OA\Property(property: 'city', type: 'string', example: 'Bratislava', nullable: true),
        new OA\Property(property: 'postal_code', type: 'string', example: '811 01', nullable: true),
        new OA\Property(property: 'vat_id', type: 'string', example: 'SK1234567890', nullable: true),
        new OA\Property(property: 'website', type: 'string', example: 'https://example.com', nullable: true),
        new OA\Property(property: 'logo_path', type: 'string', nullable: true),
        new OA\Property(property: 'invoice_footer_text', type: 'string', nullable: true),
        new OA\Property(property: 'overdue_reminder_days', type: 'integer', example: 14),
        new OA\Property(property: 'auto_remind_enabled', type: 'boolean', example: false),
        new OA\Property(property: 'auto_remind_max', type: 'integer', example: 3),
        new OA\Property(property: 'auto_remind_interval_days', type: 'integer', example: 7),
        new OA\Property(property: 'has_clockify_api_key', type: 'boolean', example: false),
        new OA\Property(property: 'clockify_workspace_id', type: 'string', nullable: true),
        new OA\Property(property: 'has_password', type: 'boolean', example: true),
        new OA\Property(property: 'has_google_auth', type: 'boolean', example: false),
        new OA\Property(property: 'two_factor_enabled', type: 'boolean', example: false),
        new OA\Property(property: 'uses_flat_rate', type: 'boolean', example: false),
        new OA\Property(property: 'email_verified', type: 'boolean', example: true),
        new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'member', 'viewer'], nullable: true),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string', example: 'clients.manage')),
        new OA\Property(property: 'is_team_member', type: 'boolean', example: false),
        new OA\Property(
            property: 'owner',
            description: 'Account owner info when this user is a team member',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'full_name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(property: 'plan', type: 'string', example: 'pro', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'name' => $this->resource->name,
            'surname' => $this->resource->surname,
            'full_name' => $this->resource->full_name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'avatar_path' => $this->resource->avatar_path,
            'color' => $this->resource->color,
            'ico' => $this->resource->ico,
            'dic' => $this->resource->dic,
            'is_vat_payer' => $this->resource->is_vat_payer,
            'vat_status' => $this->resource->vat_status->value,
            'tax_flat_rate' => $this->resource->tax_flat_rate,
            'default_currency' => $this->resource->default_currency?->value,
            'invoice_prefix' => $this->resource->invoice_prefix,
            'invoice_number_mask' => $this->resource->invoice_number_mask,
            'invoice_number_start' => $this->resource->invoice_number_start,
            'locale' => $this->resource->locale,
            'country' => $this->resource->country,
            'address' => $this->resource->address,
            'city' => $this->resource->city,
            'postal_code' => $this->resource->postal_code,
            'vat_id' => $this->resource->vat_id,
            'website' => $this->resource->website,
            'logo_path' => $this->resource->logo_path,
            'invoice_footer_text' => $this->resource->invoice_footer_text,
            'overdue_reminder_days' => $this->resource->overdue_reminder_days,
            'auto_remind_enabled' => $this->resource->auto_remind_enabled,
            'auto_remind_max' => $this->resource->auto_remind_max,
            'auto_remind_interval_days' => $this->resource->auto_remind_interval_days,
            'has_clockify_api_key' => $this->resource->clockify_api_key !== null,
            'clockify_workspace_id' => $this->resource->clockify_workspace_id,
            'has_password' => $this->resource->hasPassword(),
            'has_google_auth' => $this->resource->hasGoogleAuth(),
            'two_factor_enabled' => $this->resource->hasTwoFactorEnabled(),
            'uses_flat_rate' => $this->resource->usesFlatRate(),
            'email_verified' => $this->resource->email_verified_at !== null,

            'role' => $this->resource->roleName(),
            'permissions' => $this->resource->permissionNames(),
            'is_team_member' => $this->resource->isTeamMember(),
            'owner' => $this->resource->accountOwnerMeta(),

            // Subscription — SaaS only, and only when loaded
            'plan' => $this->when(
                $this->resource->exposesPlan(),
                fn () => $this->resource->planSlug(),
            ),

            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
