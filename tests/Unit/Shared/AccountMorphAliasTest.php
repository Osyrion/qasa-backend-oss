<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * SharedServiceProvider aliases a handful of models so `subject_type` reads as
 * 'invoice' rather than an FQCN. The account model is the one entry that
 * cannot be named as a class: the SaaS edition swaps it through
 * `auth.providers.users.model`, so a hardcoded core `User` makes the alias
 * describe a class nothing in that edition ever instantiates.
 */
it('aliases the edition account model, not the core class', function (): void {
    expect(Relation::getMorphedModel('user'))->toBe(userModel());
});

it('writes the alias, not an FQCN, as an account row morph type', function (): void {
    expect((new (userModel()))->getMorphClass())->toBe('user');
});
