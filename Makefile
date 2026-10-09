app_name := user_rename
version := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir := build/artifacts
CERT_DIR ?= $(HOME)/Dev/.nextcloud/certificates
NC_DIR ?=

.PHONY: appstore sign clean

# build/artifacts/user_rename.tar.gz, with a top-level user_rename/ directory
appstore: clean
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo img lib CHANGELOG.md LICENSE README.md $(build_dir)/$(app_name)/
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(build_dir) $(app_name)
	@echo "Built $(build_dir)/$(app_name).tar.gz ($(version))"

# Local equivalent of the release workflow's signing. Needs a Nextcloud checkout in NC_DIR.
sign: appstore
	@test -n "$(NC_DIR)" || { echo "Set NC_DIR to a Nextcloud server directory"; exit 1; }
	php $(NC_DIR)/occ integrity:sign-app \
		--privateKey=$(CERT_DIR)/$(app_name).key \
		--certificate=$(CERT_DIR)/$(app_name).crt \
		--path=$(CURDIR)/$(build_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(build_dir) $(app_name)
	openssl dgst -sha512 -sign $(CERT_DIR)/$(app_name).key $(build_dir)/$(app_name).tar.gz \
		| openssl base64 -A > $(build_dir)/$(app_name).tar.gz.sig
	@echo "Signature for the app store upload form: $(build_dir)/$(app_name).tar.gz.sig"

clean:
	rm -rf build
