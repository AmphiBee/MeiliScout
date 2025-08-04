const contentSelection = function(config) {
    return {
        postTypes: config.postTypes || [],
        taxonomies: config.taxonomies || [],
        metaKeys: config.metaKeys || [],
        nonIndexableMetaKeys: config.nonIndexableMetaKeys || [],
        activeTab: "content",
        inputRefs: [],
        fields: {
            meta_keys: []
        },
        structureChanges: config.structureChanges || { has_changed: false, changes: {}, last_indexed: null },
        showNotification: false,
        
        initRepeaterFields() {
            this.fields.meta_keys = this.metaKeys.length ? this.metaKeys : [''];
            this.checkStructureChanges();
        },

        getFields(fieldName) {
            return this.fields[fieldName];
        },

        storeRef(fieldName, el, index) {
            if (typeof this.inputRefs[fieldName] === "undefined") {
                this.inputRefs[fieldName] = [];
            }
            this.inputRefs[fieldName][index] = el;
        },

        addField(fieldName, index) {
            const targetIndex = typeof index !== "undefined" ? index : this.fields[fieldName].length;
            this.fields[fieldName].splice(targetIndex, 0, "");
            this.$nextTick(() => {
                this.$refs.scrollContainer.scrollTop = this.$refs.scrollContainer.scrollHeight;
                this.inputRefs[fieldName][targetIndex].focus();
            });
            this.checkStructureChanges();
        },

        removeField(fieldName, index) {
            this.fields[fieldName].splice(index, 1);
            if (this.fields[fieldName].length === 0) {
                this.addField(fieldName);
            }
            this.checkStructureChanges();
        },

        removeFieldAndFocusPrevious(fieldName, index) {
            if (this.fields[fieldName][index] === "") {
                this.removeField(fieldName, index);
                this.$nextTick(() => {
                    const previousIndex = index - 1 >= 0 ? index - 1 : 0;
                    if (this.inputRefs[fieldName][previousIndex]) {
                        this.inputRefs[fieldName][previousIndex].focus();
                    }
                });
            }
        },

        checkStructureChanges() {
            if (this.structureChanges.has_changed) {
                this.showNotification = true;
            }
        },

        getNotificationMessage() {
            if (!this.structureChanges.has_changed) {
                return '';
            }

            const changes = this.structureChanges.changes;
            const messages = [];

            if (changes.post_types) {
                if (changes.post_types.added.length > 0) {
                    messages.push(`Types de contenu ajoutés : ${changes.post_types.added.join(', ')}`);
                }
                if (changes.post_types.removed.length > 0) {
                    messages.push(`Types de contenu supprimés : ${changes.post_types.removed.join(', ')}`);
                }
            }

            if (changes.taxonomies) {
                if (changes.taxonomies.added.length > 0) {
                    messages.push(`Taxonomies ajoutées : ${changes.taxonomies.added.join(', ')}`);
                }
                if (changes.taxonomies.removed.length > 0) {
                    messages.push(`Taxonomies supprimées : ${changes.taxonomies.removed.join(', ')}`);
                }
            }

            if (changes.meta_keys) {
                if (changes.meta_keys.added.length > 0) {
                    messages.push(`Meta keys ajoutées : ${changes.meta_keys.added.join(', ')}`);
                }
                if (changes.meta_keys.removed.length > 0) {
                    messages.push(`Meta keys supprimées : ${changes.meta_keys.removed.join(', ')}`);
                }
            }

            const lastIndexed = this.structureChanges.last_indexed 
                ? `Dernière indexation : ${new Date(this.structureChanges.last_indexed).toLocaleString()}`
                : 'Aucune indexation précédente';

            return `Des changements ont été détectés dans la structure d'indexation. Une réindexation est nécessaire.\n${messages.join('\n')}\n${lastIndexed}`;
        },

        addNonIndexableMetaKey(metaKey) {
            if (!this.fields.meta_keys.includes(metaKey)) {
                const lastNonEmptyIndex = this.fields.meta_keys.reduce((acc, value, index) => {
                    return value !== '' ? index : acc;
                }, -1);

                this.fields.meta_keys.splice(lastNonEmptyIndex + 1, 0, metaKey);

                if (!this.fields.meta_keys.includes('')) {
                    this.fields.meta_keys.push('');
                }

                this.nonIndexableMetaKeys = this.nonIndexableMetaKeys.filter(key => key !== metaKey);

                this.$nextTick(() => {
                    this.$refs.scrollContainer.scrollTop = this.$refs.scrollContainer.scrollHeight;
                });
            }
        }
    };
};

export default contentSelection; 