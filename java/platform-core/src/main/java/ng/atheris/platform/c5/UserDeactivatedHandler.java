package ng.atheris.platform.c5;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;

import ng.atheris.platform.user.InvalidUserException;
import ng.atheris.platform.user.UserService;
import ng.atheris.platform.user.UserSyncService;
import ng.atheris.suite.inbound.SuiteEventHandler;
import ng.atheris.suite.inbound.SuiteValidationException;
import ng.atheris.suite.model.Envelope;

public class UserDeactivatedHandler implements SuiteEventHandler {

    private final UserSyncService sync;
    private final ObjectMapper mapper;

    public UserDeactivatedHandler(UserSyncService sync, ObjectMapper mapper) {
        this.sync = sync;
        this.mapper = mapper;
    }

    @Override public String type() { return UserService.DEACTIVATED; }

    @Override public String contract() { return UserService.CONTRACT; }

    @Override
    public void handle(Envelope event) {
        try {
            sync.applyDeactivated(event.tenant(), mapper.treeToValue(event.data(), UserDeactivatedData.class));
        } catch (InvalidUserException | JsonProcessingException | IllegalArgumentException e) {
            throw new SuiteValidationException(e.getMessage());
        }
    }
}
