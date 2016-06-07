$(document).ready(function() {

    $( "#node-add" ).on( "submit", function( event ) {

        $.ajax({
            type        : 'POST', // define the type of HTTP verb we want to use (POST for our form)
            url         : 'http://back.headless.dev/api/v1.0/issues', // the url where we want to POST
            data        : {
                'label' : $("input[id='name']").val(),
                'field_body' : $("input[id='body']").val()
            },
            beforeSend : setHeader,
            dataType    : 'json', // what type of data do we expect back from the server
            encode          : true,
            crossDomain : true
        })
            // using the done promise callback
            .done(function(data) {
                // log data to the console so we can see
                console.log(data);
                alert('OK');
                $( "#node-add").html("OK");
                // here we will handle errors and validation messages
            })

            .fail(function(){

                alert('FAIL');
            });

        function setHeader(xhr) {
            xhr.setRequestHeader ("Authorization", "Basic YWRtaW46YWRtaW4=");
        }

        event.preventDefault();

    });

});
