{{-- Plugin override of the core link view. Discovers every `'user'@'host'`
     row for this username and applies the REVOKE + GRANT to each one, so
     the dual `localhost` + `127.0.0.1` pair (and any extra remote rows)
     created by create-user.blade.php all end up with identical privileges
     on the linked database.

     The REVOKE step matches the core view's behavior: it's a clean-slate
     pass that's allowed to fail (mysqld returns 1141 "no such grant" when
     the user has no privileges on the database yet) — we explicitly ignore
     a non-zero exit so the GRANT can still run. The GRANT itself is the
     source of truth and must succeed. --}}
@php
    $grants = match($permission ?? 'admin') {
        'read' => 'SELECT, SHOW VIEW',
        'write' => 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, LOCK TABLES, REFERENCES, SHOW VIEW, TRIGGER, CREATE VIEW, EXECUTE',
        default => 'ALL PRIVILEGES'
    };
@endphp

HOSTS=$(sudo mysql -BN -e "SELECT Host FROM mysql.user WHERE User='{{ $username }}'")
if [ -z "$HOSTS" ]; then
    echo "ERROR: no '{{ $username }}'@... rows found in mysql.user — was the user created?"
    echo 'VITO_SSH_ERROR' && exit 1
fi

for H in $HOSTS; do
    sudo mysql -e "REVOKE ALL PRIVILEGES ON {{ $database }}.* FROM '{{ $username }}'@'$H'" 2>/dev/null || true

    if ! sudo mysql -e "GRANT {{ $grants }} ON {{ $database }}.* TO '{{ $username }}'@'$H'"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
done

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Linking to {{ $database }} finished"
