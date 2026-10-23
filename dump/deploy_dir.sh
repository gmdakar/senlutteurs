#!/usr/bin/env bash
rm sync.tar.gz ; tar --exclude='.git' --exclude='.ddev' -czf sync.tar.gz config/sync ; ls
rm vendor.tar.gz ; tar --exclude='.git' --exclude='.ddev' -czf vendor.tar.gz vendor ; ls


