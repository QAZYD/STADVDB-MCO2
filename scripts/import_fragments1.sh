#!/bin/bash
# =============================================================================
# Script: import_fragments1.sh
# Purpose: Import Node1 fragment (Users_node1.sql) into Server1
# =============================================================================

BASE_DIR="/var/www/html/myProject"
USER="G9_1"
PASS="pass1234"
DB="faker"
FRAGMENT="${BASE_DIR}/Node1/Users_node1.sql"

echo "Using DB credentials:"
echo "User: $USER"
echo "DB:   $DB"
echo

echo "Importing Node1 fragment..."
mysql -u $USER -p$PASS $DB < "$FRAGMENT" && echo "✔ Successfully imported $FRAGMENT" || echo "✖ Failed to import $FRAGMENT"

echo
echo "All imports finished."
