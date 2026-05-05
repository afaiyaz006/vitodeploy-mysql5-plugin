{{-- Discover every `'user'@'host'` row for this username and drop each
     one. This handles the dual `localhost` + `127.0.0.1` pair created by
     create-user.blade.php (plus any extra remote rows) without Vito having
     to track the host set itself.

     MySQL 5.5 / 5.6 don't support `DROP USER IF EXISTS` (added in 5.7.8),
     but the `SELECT Host ... WHERE User=...` is itself the existence
     check: if no rows match, the for-loop body never runs and we exit
     idempotently. --}}
HOSTS=$(sudo mysql -BN -e "SELECT Host FROM mysql.user WHERE User='{{ $username }}'")
for H in $HOSTS; do
    if ! sudo mysql -e "DROP USER '{{ $username }}'@'$H'"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
done

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
