{{-- Discover every `'user'@'host'` row for this username and update each
     one. This handles the dual `localhost` + `127.0.0.1` pair created by
     create-user.blade.php (plus any extra remote rows) without Vito having
     to track the host set itself.

     SQL syntax for changing a password differs by server version:
       - 5.5 / 5.6: `SET PASSWORD FOR ... = PASSWORD('...')` — the
         PASSWORD() function still exists.
       - 5.7+: PASSWORD() was deprecated in 5.7 and removed in 8.0; use
         `ALTER USER ... IDENTIFIED BY '...'` instead.

     We detect the server's major.minor at runtime so this single view works
     for every version the mysql5 plugin supports (5.5 / 5.6 / 5.7).

     The previous `$newHost` rename branch is intentionally gone. With the
     dual-host scheme in create-user.blade.php, "host" is no longer a
     primary identity for a Vito-managed user — every user has at least the
     localhost + 127.0.0.1 pair. If the UI ever needs to add or remove a
     remote-host row, that should be a separate operation rather than a
     RENAME. --}}
@if ($newPassword)
MYSQL_VER=$(sudo mysql -BN -e 'SELECT VERSION()' | grep -oE '^[0-9]+\.[0-9]+')
HOSTS=$(sudo mysql -BN -e "SELECT Host FROM mysql.user WHERE User='{{ $username }}'")
for H in $HOSTS; do
    case "$MYSQL_VER" in
        5.5|5.6)
            SQL="SET PASSWORD FOR '{{ $username }}'@'$H' = PASSWORD('{{ $newPassword }}')"
            ;;
        *)
            SQL="ALTER USER '{{ $username }}'@'$H' IDENTIFIED BY '{{ $newPassword }}'"
            ;;
    esac
    if ! sudo mysql -e "$SQL"; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
done
@endif

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
