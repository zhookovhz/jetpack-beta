(function () {
    // Elements
    var sections = document.getElementById('section-pr').querySelectorAll('.branch-card');
    var search_input = document.getElementById('search-component');
    var search_close_link = document.getElementById('search-component-close');
    var activate_links = document.querySelectorAll('.activate-branch');
    var toggle_links = document.querySelectorAll('.form-toggle__label');


    var section_index = []; //
    var each = Array.prototype.forEach;
    var clicked_activate = false;
    var clicked_toggle = false;

    each.call(sections, function (element, index) {
        hide( element );
        element.querySelector('.activate-branch').setAttribute('data-index', index);
        section_index[index] = {
            header: element.querySelector('.branch-card-header').textContent,
            pr: element.getAttribute('data-pr'),
            element: element
        }
    });

    // Search input
    search_input.addEventListener("keyup", function (event) {
        var search_for = pr_to_header(search_input.value);

        if (!search_for) {
            hide( search_close_link );
            hide_section();
            return;
        }

        show( search_close_link );
        section_index.forEach( show_found_branches.bind( this, search_for ) );
    });

    function format_branch_for_autocomplete( branch ) {
        return '#' + branch.pr.toString() + ' - ' + branch.header;
    }

    function show_found_branches(search_for, branch) {
        var element = branch.element;
        var header_text = ( parseInt(search_for) > 0 ) ? format_branch_for_autocomplete( branch ) : branch.header;

        var found_position = header_text.indexOf(search_for);
        if (-1 === found_position) {
            hide( element );
            return;
        }

        element.querySelector('.branch-card-header').innerHTML = highlight_word(search_for, header_text);
        show( element );
    }

    // Search close link
    hide( search_close_link );
    search_close_link.addEventListener('click', function (event) {
        hide_section();
        hide( search_close_link );
        search_input.value = '';
        event.preventDefault();
    });

    // Activate Links
    each.call(activate_links, function (element, index) {
        element.addEventListener('click', activate_link_click.bind( this, element ) );
    });

    function activate_link_click( element, event ) {
        if ( clicked_activate ) {
           return;
        }
        if ( element.textContent == JetpackBeta.activate ) {
            element.parentNode.textContent = JetpackBeta.activating;
        } else {
            element.parentNode.textContent = JetpackBeta.updating;
        }

        var index = parseInt( element.getAttribute('data-index') );

        sections = Array.prototype.filter.call( sections, function( element, i ) {
            return (index === i ? false: true );
        } );
        disable_activete_branch_links();
        clicked_activate = true;
    }

    function disable_activete_branch_links() {
        each.call(activate_links, function (element, index) {
            element.addEventListener('click', function (event) {
                event.preventDefault();
            } );
            element.removeEventListener( 'click', activate_link_click.bind( this, element ) );
            element.classList.add('is-disabled');
        })
    }

    // Toggle Links
    each.call( toggle_links, function( element, index ) {
        element.addEventListener('click', toggle_link_click.bind( this, element ) );


    } );
    function toggle_link_click( element, event ) {
        if ( clicked_toggle ) {
            return;
        }
        clicked_toggle = true;
        element.classList.toggle('is-active');
    }

    // Helper functions
    function pr_to_header(search) {
        return search.replace("/", " / ").replace(new RegExp("\\-", "g"), " ").replace(/  +/g, ' ').toLowerCase();
    }

    function highlight_word(word, phrase) {
        var regExp = new RegExp(word, 'g');
        var replace = '<span class="highlight">' + word + '</span>';
        return phrase.replace(regExp, replace);
    }

    function hide_section() {
        each.call( sections, hide );
    }

    function hide( element ) {
        element.style.display = 'none';
    }

    function show( element ) {
        element.style.display = '';
    }
})();
