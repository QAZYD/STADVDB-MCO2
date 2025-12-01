#!/bin/bash
# =============================================================================
# Script: import_fragments2.sh
# Purpose: Import Node2 fragment (Users_node2.sql) into Server2
# =============================================================================

BASE_DIR="/var/www/html/myProject"
USER="G9_1"
PASS="pass1234"
DB="faker"
FRAGMENT="${BASE_DIR}/Node2/Users_node2.sql"

echo "Using DB credentials:"
echo "User: $USER"
echo "DB:   $DB"
echo

echo "Importing Node2 fragment..."
mysql -u $USER -p$PASS $DB < "$FRAGMENT" && echo "✔ Successfully imported $FRAGMENT" || echo "✖ Failed to import $FRAGMENT"

echo
echo "All imports finished."
