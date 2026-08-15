<div class="favorite-list-item">
    @if($user)
        <div data-id="{{ $user->id }}" data-action="0" class="avatar av-m"
            style="background-image: url('{{ Chatify::getUserWithAvatar($user)->image }}');">
        </div>
        <p>{{ strlen($user->customer_name ?? $user->name ?? '') > 5 ? substr($user->customer_name ?? $user->name ?? '',0,6).'..' : ($user->customer_name ?? $user->name ?? '') }}</p>
    @endif
</div>
