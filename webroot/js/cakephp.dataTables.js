String.prototype.replaceAll = function(target, replacement) {
 return this.split(target).join(replacement);
};

jQuery.fn.dataTable.ext.buttons.csvExport = {
    text: 'Download',
    className: 'btn btn-primary btn-sm',
    action: function (e, dt, node, config) {
        var params = dt.ajax.params() || {};
        var url = dt.ajax.url();
        params._csv_output = true;
        var iframe;
        iframe = document.getElementById("hiddenDownloader");
        if (iframe === null) {
            iframe = document.createElement('iframe');
            iframe.id = "hiddenDownloader";
            iframe.style.height = "0px";
            iframe.style.width = "0px";
            document.body.appendChild(iframe);
        }
        iframe.src = url +"?" + jQuery.param(params);
    }
};

var initDataTable = (function () {
    var defaults = {
        orderCellsTop: true,
        ajax: {
            dataSrc: 'data'
        },
        searching: true,
        processing: false,
        serverSide: true,
        dom: '<"datatable-header"fl><"datatable-scroll"t>r<"datatable-footer"ip>',
        autoWidth: false,
        language: {
            'search' : '<span>Filter:</span> _INPUT_',
            'lengthMenu' : '<span>Show:</span> _MENU_',
            'paginate' : { 'first' : 'First', 'last' : 'Last', 'next' : '→', 'previous' : '←' }
        },
    };
    

    function stopPropagation(evt) {
        if (evt.stopPropagation !== undefined) {
            evt.stopPropagation();
        } else {
            evt.cancelBubble = true;
        }
    }

    return function  (tableSelector, options) {

        var existingDrawCallback = options.drawCallback;
        // Monkey patching
    	options.drawCallback = function () {
            if (existingDrawCallback) {
                existingDrawCallback.apply(this, arguments);
            }
            $(this).find('tbody tr').slice(-3).find('.dropdown, .btn-group').addClass('dropup');
        };
        options.preDrawCallback = function() {
            $(this).find('tbody tr').slice(-3).find('.dropdown, .btn-group').removeClass('dropup');
        };

        var settings = $.extend(true, {}, defaults, options);
        if (settings.buttons) {
            settings.dom += '<B>';
        }

        var table = jQuery(tableSelector).DataTable(settings);
        var $searchBox = jQuery(tableSelector+'_filter input');
        $searchBox.unbind();
        var onKeyUp = (function() {
            var isSearched = false;
            return function(e) {
                if (e.keyCode === 13 && !(this.value === "")) {
                    table.search(this.value).draw();
                    isSearched = true;
                } else if (isSearched && this.value === "") {
                    table.search(this.value).draw();
                    isSearched = false;
                }
            };
        }());
        $searchBox.bind('keyup', onKeyUp);

        // Apply the filter
        jQuery(tableSelector+' thead tr:last :input[type=text]').on('keyup', (function () {
            var isSearched = false;
            return function(e) {
                if (e.keyCode === 13 && !(this.value === "")) {
                    table.column(jQuery(this).closest('th').index()+':visible').search(this.value).draw();
                    isSearched = true;
                } else if (isSearched && this.value === "") {
                    table.column(jQuery(this).closest('th').index()+':visible').search(this.value).draw();
                    isSearched = false;
                }
               
            }
        }()));
        
        // Apply the filter
        jQuery(tableSelector+' thead tr:last select').on('change', function (e) {
           table
               .column(jQuery(this).closest('th').index()+':visible')
               .search(this.value, true) // Hack used regex field for wild card search or full search options.
               .draw();
        });

        var $checkbox = jQuery(tableSelector+' thead tr:first th:first input[type="checkbox"]');
        if ($checkbox.length) {
            var chkbox_select_all = $checkbox.get(0);
            var haveIndeterminate = 'indeterminate' in chkbox_select_all;
            chkbox_select_all.checked = false;
            
            $checkbox.on('click', function () {
                if (jQuery(this).is(':checked')) {
                    table.rows().select();    
                } else {
                    table.rows().deselect();
                }
            });
            
            table.on('select', function () {
                var selectedRows = table.rows({selected: true}).count();
                if(haveIndeterminate){
                    chkbox_select_all.checked = true;
                    if (table.data().count() === selectedRows) {
                        chkbox_select_all.indeterminate = false;
                    } else {
                        chkbox_select_all.indeterminate = true;
                    }
                } else if (table.data().count() === selectedRows) {
                    chkbox_select_all.checked = true;
                }
            });

            table.on('deselect', function () {
                var selectedRows = table.rows({selected: true}).count();
                if (selectedRows === 0) {
                    chkbox_select_all.checked = false;
                    if(haveIndeterminate){
                        chkbox_select_all.indeterminate = false;
                    }
                } else if (table.data().count() !== selectedRows) {
                    chkbox_select_all.checked = true;
                    if(haveIndeterminate){
                        chkbox_select_all.indeterminate = true;
                    }
                }
            });
        }
        
        //table.buttons().container().appendTo(tableSelector+'_buttons');
        // Add placeholder to the datatable filter option
        $('.dataTables_filter input[type=search]').attr('placeholder','Search');
        // Enable Select2 select for the length option
        $('.dataTables_length select').select2({
            minimumResultsForSearch: Infinity,
            width: 'auto'
        });
        return table;
    };

}());