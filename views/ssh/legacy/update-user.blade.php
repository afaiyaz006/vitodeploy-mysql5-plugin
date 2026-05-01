{{-- MySQL 5.5 / 5.6 don't support `ALTER USER ... IDENTIFIED BY '...'`
     (added in 5.7.6). The pre-5.7 idiom for changing a password is
     `SET PASSWORD ... = PASSWORD('...')`. RENAME USER works in all
     versions back to 5.0, so the host-rename branch is unchanged. --}}
@if ($newPassword)
if ! sudo mysql -e "SET PASSWORD FOR '{{ $username }}'@'{{ $host }}' = PASSWORD('{{ $newPassword }}')"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
@endif

@if ($newHost && $newHost != $host)
if ! sudo mysql -e "RENAME USER '{{ $username }}'@'{{ $host }}' TO '{{ $username }}'@'{{ $newHost }}'"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
@endif

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
